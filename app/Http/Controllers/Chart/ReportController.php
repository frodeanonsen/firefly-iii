<?php
/**
 * ReportController.php
 * Copyright (c) 2017 thegrumpydictator@gmail.com
 *
 * This file is part of Firefly III.
 *
 * Firefly III is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Firefly III is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Firefly III. If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace FireflyIII\Http\Controllers\Chart;

use Carbon\Carbon;
use FireflyIII\Generator\Chart\Basic\GeneratorInterface;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Helpers\Report\NetWorthInterface;
use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionType;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Support\CacheProperties;
use FireflyIII\Support\Http\Controllers\BasicDataSupport;
use FireflyIII\Support\Http\Controllers\ChartGeneration;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Log;

/**
 * Class ReportController.
 */
class ReportController extends Controller
{
    use BasicDataSupport, ChartGeneration;
    /** @var GeneratorInterface Chart generation methods. */
    protected $generator;

    /**
     * ReportController constructor.
     *
     * @codeCoverageIgnore
     */
    public function __construct()
    {
        parent::__construct();
        // create chart generator:
        $this->generator = app(GeneratorInterface::class);
    }

    /**
     * This chart, by default, is shown on the multi-year and year report pages,
     * which means that giving it a 2 week "period" should be enough granularity.
     *
     * @param Collection $accounts
     * @param Carbon     $start
     * @param Carbon     $end
     *
     * @return JsonResponse
     */
    public function netWorth(Collection $accounts, Carbon $start, Carbon $end): JsonResponse
    {
        // chart properties for cache:
        $cache = new CacheProperties;
        $cache->addProperty('chart.report.net-worth');
        $cache->addProperty($start);
        $cache->addProperty(implode(',', $accounts->pluck('id')->toArray()));
        $cache->addProperty($end);
        if ($cache->has()) {
            return response()->json($cache->get()); // @codeCoverageIgnore
        }
        $current   = clone $start;
        $chartData = [];
        /** @var NetWorthInterface $helper */
        $helper = app(NetWorthInterface::class);
        $helper->setUser(auth()->user());

        // filter accounts on having the preference for being included.
        /** @var AccountRepositoryInterface $accountRepository */
        $accountRepository = app(AccountRepositoryInterface::class);
        $filtered          = $accounts->filter(
            static function (Account $account) use ($accountRepository) {
                $includeNetWorth = $accountRepository->getMetaValue($account, 'include_net_worth');
                $result          = null === $includeNetWorth ? true : '1' === $includeNetWorth;
                if (false === $result) {
                    Log::debug(sprintf('Will not include "%s" in net worth charts.', $account->name));
                }

                return $result;
            }
        );

        // TODO get liabilities and include those as well?

        while ($current < $end) {
            // get balances by date, grouped by currency.
            $result = $helper->getNetWorthByCurrency($filtered, $current);

            // loop result, add to array.
            /** @var array $netWorthItem */
            foreach ($result as $netWorthItem) {
                $currencyId = $netWorthItem['currency']->id;
                $label      = $current->formatLocalized((string)trans('config.month_and_day'));
                if (!isset($chartData[$currencyId])) {
                    $chartData[$currencyId] = [
                        'label'           => 'Net worth in ' . $netWorthItem['currency']->name,
                        'type'            => 'line',
                        'currency_symbol' => $netWorthItem['currency']->symbol,
                        'entries'         => [],
                    ];
                }
                $chartData[$currencyId]['entries'][$label] = $netWorthItem['balance'];

            }
            $current->addDays(7);
        }

        $data = $this->generator->multiSet($chartData);
        $cache->store($data);

        return response()->json($data);
    }

    /**
     * Shows income and expense, debit/credit: operations.
     *
     * @param Collection $accounts
     * @param Carbon     $start
     * @param Carbon     $end
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function operations(Collection $accounts, Carbon $start, Carbon $end): JsonResponse
    {
        // chart properties for cache:
        $cache = new CacheProperties;
        $cache->addProperty('chart.report.operations');
        $cache->addProperty($start);
        $cache->addProperty($accounts);
        $cache->addProperty($end);
        if ($cache->has()) {
            return response()->json($cache->get()); // @codeCoverageIgnore
        }
        Log::debug('Going to do operations for accounts ', $accounts->pluck('id')->toArray());
        $format      = app('navigation')->preferredCarbonFormat($start, $end);
        $titleFormat = app('navigation')->preferredCarbonLocalizedFormat($start, $end);
        $ids         = $accounts->pluck('id')->toArray();

        // get journals for entire period:
        $data      = [];
        $chartData = [];
        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setRange($start, $end)->setAccounts($accounts)->withAccountInformation();
        $journals = $collector->getExtractedJournals();

        // loop. group by currency and by period.
        /** @var array $journal */
        foreach ($journals as $journal) {
            $period                     = $journal['date']->format($format);
            $currencyId                 = (int)$journal['currency_id'];
            $data[$currencyId]          = $data[$currencyId] ?? [
                    'currency_id'             => $currencyId,
                    'currency_symbol'         => $journal['currency_symbol'],
                    'currency_code'           => $journal['currency_symbol'],
                    'currency_name'           => $journal['currency_name'],
                    'currency_decimal_places' => $journal['currency_decimal_places'],
                ];
            $data[$currencyId][$period] = $data[$currencyId][$period] ?? [
                    'period' => $period,
                    'spent'  => '0',
                    'earned' => '0',
                ];
            // in our outgoing?
            $key    = 'spent';
            $amount = app('steam')->positive($journal['amount']);
            if (TransactionType::DEPOSIT === $journal['transaction_type_type']
                || (TransactionType::TRANSFER === $journal['transaction_type_type']
                    && in_array(
                        $journal['destination_id'], $ids, true
                    ))) {
                $key = 'earned';
            }
            $data[$currencyId][$period][$key] = bcadd($data[$currencyId][$period][$key], $amount);
        }

        // loop this data, make chart bars for each currency:
        /** @var array $currency */
        foreach ($data as $currency) {
            $income  = [
                'label'           => (string)trans('firefly.box_earned_in_currency', ['currency' => $currency['currency_name']]),
                'type'            => 'bar',
                'backgroundColor' => 'rgba(0, 141, 76, 0.5)', // green
                'currency_id'     => $currency['currency_id'],
                'currency_symbol' => $currency['currency_symbol'],
                'entries'         => [],
            ];
            $expense = [
                'label'           => (string)trans('firefly.box_spent_in_currency', ['currency' => $currency['currency_name']]),
                'type'            => 'bar',
                'backgroundColor' => 'rgba(219, 68, 55, 0.5)', // red
                'currency_id'     => $currency['currency_id'],
                'currency_symbol' => $currency['currency_symbol'],
                'entries'         => [],

            ];
            // loop all possible periods between $start and $end
            $currentStart = clone $start;
            while ($currentStart <= $end) {
                $currentEnd                 = app('navigation')->endOfPeriod($currentStart, '1M');
                $key                        = $currentStart->format($format);
                $title                      = $currentStart->formatLocalized($titleFormat);
                $income['entries'][$title]  = round($currency[$key]['earned'] ?? '0', $currency['currency_decimal_places']);
                $expense['entries'][$title] = round($currency[$key]['spent'] ?? '0', $currency['currency_decimal_places']);

                $currentStart = app('navigation')->addPeriod($currentStart, '1M', 0);
            }

            $chartData[] = $income;
            $chartData[] = $expense;
        }

        $data = $this->generator->multiSet($chartData);
        $cache->store($data);

        return response()->json($data);
    }
}
