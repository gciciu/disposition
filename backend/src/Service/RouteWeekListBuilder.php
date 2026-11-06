<?php

namespace App\Service;

use App\Entity\DeliveryRoute;
use App\Repository\DeliveryRouteRepository;

class RouteWeekListBuilder
{
    private const WEEKDAY_LABELS = ['Mo', 'Tu', 'We', 'Th', 'Fr'];

    public function __construct(
        private readonly DeliveryRouteRepository $routes,
    ) {
    }

    /**
     * @return array{weeks: list<array<string, mixed>>, unscheduled: list<array<string, mixed>>}
     */
    public function build(): array
    {
        $all = $this->routes->findAllNewestFirst();
        $weeksMap = [];
        $unscheduled = [];

        foreach ($all as $route) {
            $courier = $route->getCourier();
            $scheduledDate = $route->getScheduledDate();

            if (null === $courier || null === $courier->getId() || null === $scheduledDate) {
                $unscheduled[] = $route->toListArray();
                continue;
            }

            $weekStart = $this->mondayOfWeek($scheduledDate);
            $weekEnd = $weekStart->modify('+4 days');
            $key = $courier->getId().'|'.$weekStart->format('Y-m-d');

            if (!isset($weeksMap[$key])) {
                $weeksMap[$key] = [
                    'weekStart' => $weekStart->format('Y-m-d'),
                    'weekEnd' => $weekEnd->format('Y-m-d'),
                    'title' => '',
                    'routeCode' => null,
                    'courier' => [
                        'id' => $courier->getId(),
                        'name' => $courier->getUser()?->getName() ?? '',
                        'login' => $courier->getUser()?->getEmail() ?? '',
                        'phone' => $courier->getPhone(),
                    ],
                    'subcontractor' => $courier->getSubcontractor(),
                    'orderCount' => 0,
                    'completedCount' => 0,
                    'totalWeightKg' => 0.0,
                    'hasWeight' => false,
                    'totalDurationSeconds' => 0,
                    'hasDuration' => false,
                    'totalDistanceMeters' => 0,
                    'hasDistance' => false,
                    'daysByDate' => [],
                    '_sortName' => mb_strtolower($courier->getUser()?->getName() ?? ''),
                ];

                for ($i = 0; $i < 5; ++$i) {
                    $dayDate = $weekStart->modify(sprintf('+%d days', $i));
                    $dateKey = $dayDate->format('Y-m-d');
                    $weeksMap[$key]['daysByDate'][$dateKey] = [
                        'date' => $dateKey,
                        'weekday' => $i + 1,
                        'label' => self::WEEKDAY_LABELS[$i],
                        'route' => null,
                    ];
                }
            }

            $dateKey = $scheduledDate->format('Y-m-d');
            if (!isset($weeksMap[$key]['daysByDate'][$dateKey])) {
                $unscheduled[] = $route->toListArray();
                continue;
            }

            $list = $route->toListArray();
            $weeksMap[$key]['daysByDate'][$dateKey]['route'] = $list;
            $weeksMap[$key]['orderCount'] += (int) ($list['orderCount'] ?? 0);
            $weeksMap[$key]['completedCount'] += (int) ($list['completedCount'] ?? 0);

            if (null === $weeksMap[$key]['routeCode']) {
                $weeksMap[$key]['routeCode'] = $this->extractRouteCode((string) ($list['name'] ?? ''));
            }

            if (null !== $list['totalWeightKg']) {
                $weeksMap[$key]['totalWeightKg'] += (float) $list['totalWeightKg'];
                $weeksMap[$key]['hasWeight'] = true;
            }
            if (null !== $list['totalDurationSeconds']) {
                $weeksMap[$key]['totalDurationSeconds'] += (int) $list['totalDurationSeconds'];
                $weeksMap[$key]['hasDuration'] = true;
            }
            if (null !== $list['totalDistanceMeters']) {
                $weeksMap[$key]['totalDistanceMeters'] += (int) $list['totalDistanceMeters'];
                $weeksMap[$key]['hasDistance'] = true;
            }
        }

        $weeks = array_values($weeksMap);
        usort($weeks, static function (array $a, array $b): int {
            $byWeek = strcmp($b['weekStart'], $a['weekStart']);
            if (0 !== $byWeek) {
                return $byWeek;
            }

            return strcmp($a['_sortName'], $b['_sortName']);
        });

        foreach ($weeks as &$week) {
            $week['days'] = array_values($week['daysByDate']);
            $week['title'] = $this->buildWeekTitle($week);
            $week['totalWeightKg'] = $week['hasWeight'] ? round((float) $week['totalWeightKg'], 1) : null;
            $week['totalWeightText'] = $week['hasWeight']
                ? DeliveryRoute::formatWeight((float) $week['totalWeightKg'])
                : null;
            $week['totalDurationSeconds'] = $week['hasDuration'] ? (int) $week['totalDurationSeconds'] : null;
            $week['totalDurationText'] = $week['hasDuration']
                ? DeliveryRoute::formatDuration((int) $week['totalDurationSeconds'])
                : null;
            $week['totalDistanceMeters'] = $week['hasDistance'] ? (int) $week['totalDistanceMeters'] : null;
            $week['totalDistanceText'] = $week['hasDistance']
                ? DeliveryRoute::formatDistance((int) $week['totalDistanceMeters'])
                : null;
            unset(
                $week['daysByDate'],
                $week['_sortName'],
                $week['hasWeight'],
                $week['hasDuration'],
                $week['hasDistance'],
            );
        }
        unset($week);

        return [
            'weeks' => $weeks,
            'unscheduled' => $unscheduled,
        ];
    }

    /**
     * @param array<string, mixed> $week
     */
    private function buildWeekTitle(array $week): string
    {
        $parts = [];
        $code = $week['routeCode'] ?? null;
        if (\is_string($code) && '' !== $code) {
            $parts[] = $code;
        }

        $driverName = trim((string) ($week['courier']['name'] ?? ''));
        if ('' !== $driverName) {
            $parts[] = $driverName;
        }

        if (!empty($week['hasWeight'])) {
            $parts[] = DeliveryRoute::formatWeight((float) $week['totalWeightKg']);
        }

        if (!empty($week['hasDuration'])) {
            $parts[] = DeliveryRoute::formatDuration((int) $week['totalDurationSeconds']);
        }

        if ([] !== $parts) {
            return implode(' ', $parts);
        }

        return sprintf(
            '%s – %s',
            $week['weekStart'] ?? '',
            $week['weekEnd'] ?? '',
        );
    }

    private function extractRouteCode(string $routeName): ?string
    {
        if (preg_match('/^([A-Za-zÄÖÜäöü]+)\s+(\d+\/\d+)/u', trim($routeName), $m)) {
            return mb_strtoupper($m[1]).' '.$m[2];
        }

        return null;
    }

    private function mondayOfWeek(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $dayOfWeek = (int) $date->format('N'); // 1=Mon … 7=Sun
        if (1 === $dayOfWeek) {
            return $date->setTime(0, 0);
        }

        return $date->modify(sprintf('-%d days', $dayOfWeek - 1))->setTime(0, 0);
    }
}
