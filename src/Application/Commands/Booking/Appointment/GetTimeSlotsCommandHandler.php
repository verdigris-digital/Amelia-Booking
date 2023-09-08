<?php

namespace AmeliaBooking\Application\Commands\Booking\Appointment;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Services\TimeSlot\TimeSlotService as ApplicationTimeSlotService;
use AmeliaBooking\Domain\Entity\User\Provider;
use AmeliaBooking\Domain\Services\Entity\EntityService;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Bookable\Service\Service;
use AmeliaBooking\Domain\Entity\Booking\SlotsEntities;
use AmeliaBooking\Domain\Services\DateTime\DateTimeService;
use AmeliaBooking\Domain\Services\Settings\SettingsService;
use AmeliaBooking\Domain\ValueObjects\PositiveDuration;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\Booking\Appointment\AppointmentRepository;
use AmeliaBooking\Infrastructure\Services\Google\GoogleCalendarService;
use AmeliaBooking\Infrastructure\Services\Outlook\OutlookCalendarService;
use DateTimeZone;
use Exception;
use Interop\Container\Exception\ContainerException;
use Slim\Exception\ContainerValueNotFoundException;

/**
 * Class GetTimeSlotsCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Booking\Appointment
 */
class GetTimeSlotsCommandHandler extends CommandHandler
{
    /**
     * @var array
     */
    public $mandatoryFields = [
        'serviceId'
    ];

    /**
     * @param GetTimeSlotsCommand $command
     *
     * @return CommandResult
     * @throws ContainerValueNotFoundException
     * @throws ContainerException
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws QueryExecutionException
     */
    public function handle(GetTimeSlotsCommand $command)
    {
        $result = new CommandResult();

        $this->checkMandatoryFields($command);

        /** @var EntityService $entityService */
        $entityService = $this->container->get('domain.entity.service');

        /** @var ApplicationTimeSlotService $applicationTimeSlotService */
        $applicationTimeSlotService = $this->container->get('application.timeSlot.service');

        /** @var SettingsService $settingsDS */
        $settingsDS = $this->container->get('domain.settings.service');

        $isFrontEndBooking = $command->getField('page') === 'booking' || $command->getField('page') === 'cabinet';

        /** @var SlotsEntities $slotsEntities */
        $slotsEntities = $applicationTimeSlotService->getSlotsEntities(
            [
                'isFrontEndBooking' => $isFrontEndBooking,
                'providerIds'       => $command->getField('providerIds'),
            ]
        );

        $settings = $applicationTimeSlotService->getSlotsSettings($isFrontEndBooking, $slotsEntities);

        $props = [
            'serviceId'            => $command->getField('serviceId'),
            'providerIds'          => $command->getField('providerIds'),
            'locationId'           => $command->getField('locationId'),
            'extras'               => $command->getField('extras'),
            'excludeAppointmentId' => $command->getField('excludeAppointmentId'),
            'personsCount'         => $command->getField('group') ? $command->getField('persons') : null,
            'isFrontEndBooking'    => $isFrontEndBooking,
            'totalPersons'         => $command->getField('persons'),
        ];

        $lastBookedProviderId = null;

        /** @var SlotsEntities $filteredSlotEntities */
        $filteredSlotEntities = $entityService->getFilteredSlotsEntities(
            $settings,
            $props,
            $slotsEntities
        );

        /** @var Service $service */
        $service = $filteredSlotEntities->getServices()->getItem($props['serviceId']);

        if ($command->getField('serviceDuration')) {
            $service->setDuration(new PositiveDuration($command->getField('serviceDuration')));
        }

        $minimumBookingTimeInSeconds = $settingsDS
            ->getEntitySettings($service->getSettings())
            ->getGeneralSettings()
            ->getMinimumTimeRequirementPriorToBooking();

        $maximumBookingTimeInDays = $settingsDS
            ->getEntitySettings($service->getSettings())
            ->getGeneralSettings()
            ->getNumberOfDaysAvailableForBooking();

        $monthsLoad = $command->getField('monthsLoad');

        $loadGeneratedPeriod = $monthsLoad &&
            !$command->getField('endDateTime');

        $timeZone = $command->getField('queryTimeZone') ?: DateTimeService::getTimeZone()->getName();

        $queryStartDateTime = $command->getField('startDateTime') ?
            DateTimeService::getDateTimeObjectInTimeZone(
                $command->getField('startDateTime'),
                $timeZone
            )->setTimezone(DateTimeService::getTimeZone()) : null;

        $queryEndDateTime = $command->getField('endDateTime') ?
            DateTimeService::getDateTimeObjectInTimeZone(
                $command->getField('endDateTime'),
                $timeZone
            )->setTimezone(DateTimeService::getTimeZone()) : null;

        $minimumDateTime = $applicationTimeSlotService->getMinimumDateTimeForBooking(
            null,
            $isFrontEndBooking,
            $minimumBookingTimeInSeconds
        );

        $startDateTime = $queryStartDateTime ?:
            $applicationTimeSlotService->getMinimumDateTimeForBooking(
                null,
                $isFrontEndBooking,
                $minimumBookingTimeInSeconds
            );

        $endDateTime = $queryEndDateTime ?:
            $applicationTimeSlotService->getMaximumDateTimeForBooking(
                null,
                $isFrontEndBooking,
                $maximumBookingTimeInDays
            );

        $maximumDateTime = $applicationTimeSlotService->getMaximumDateTimeForBooking(
            null,
            $isFrontEndBooking,
            $maximumBookingTimeInDays
        );

        $maximumDateTime->setTimezone(new DateTimeZone($timeZone));

        if ($isFrontEndBooking) {
            $startDateTime = $startDateTime < $minimumDateTime ? $minimumDateTime : $startDateTime;

            $endDateTime = $endDateTime > $maximumDateTime ? $maximumDateTime : $endDateTime;
        }

        // set initial search period if query dates are not set
        if ($loadGeneratedPeriod) {
            $endDateTime = DateTimeService::getCustomDateTimeObject(
                $startDateTime->format('Y-m-d H:i:s')
            )->setTimezone(
                new DateTimeZone($timeZone)
            );

            $endDateTime->modify('first day of this month');

            $endDateTime->modify('+' . ($monthsLoad - 1) .  'months');

            $endDateTime->modify('last day of this month');

            $endDateTime->modify('+12days');

            $endDateTime->setTime(23, 59, 59);

            if ($isFrontEndBooking) {
                $endDateTime = $endDateTime > $maximumDateTime ?
                    DateTimeService::getDateTimeObjectInTimeZone(
                        $maximumDateTime->format('Y-m-d H:i'),
                        $timeZone
                    ) : $endDateTime;
            }

            $endDateTime->setTimezone(DateTimeService::getTimeZone());
        }

        /** @var Service $filteredSlotEntitiesService */
        foreach ($filteredSlotEntities->getServices()->getItems() as $filteredSlotEntitiesService) {
            if ($filteredSlotEntitiesService->getId()->getValue() === $service->getId()->getValue()) {
                $filteredSlotEntitiesService->setDuration($service->getDuration());

                break;
            }
        }

        /** @var Provider $filteredSlotEntitiesProvider */
        foreach ($filteredSlotEntities->getProviders()->getItems() as $filteredSlotEntitiesProvider) {
            /** @var Service $providerService */
            foreach ($filteredSlotEntitiesProvider->getServiceList()->getItems() as $providerService) {
                if ($providerService->getId()->getValue() === $service->getId()->getValue()) {
                    $providerService->setDuration($service->getDuration());

                    break;
                }
            }
        }

        $freeSlots = $applicationTimeSlotService->getSlotsByProps(
            $settings,
            array_merge(
                $props,
                [
                    'startDateTime' => $startDateTime,
                    'endDateTime'   => $endDateTime,
                ]
            ),
            $filteredSlotEntities
        );

        if ($loadGeneratedPeriod) {
            // search with new period until slots are not found
            while (!$freeSlots['available'] && $endDateTime && $endDateTime <= $maximumDateTime) {
                $startDateTime = DateTimeService::getCustomDateTimeObject(
                    $endDateTime->format('Y-m-d H:i:s')
                )->setTimezone(
                    new DateTimeZone($timeZone)
                );

                $startDateTime->setTime(0, 0, 0);

                $endDateTime->modify('first day of this month');

                $endDateTime->modify('+' . ($monthsLoad - 1) .  'months');

                $endDateTime->modify('last day of this month');

                $endDateTime->modify('+12days');

                $endDateTime->setTime(23, 59, 59);

                if ($isFrontEndBooking) {
                    $endDateTime = $endDateTime > $maximumDateTime ?
                        DateTimeService::getDateTimeObjectInTimeZone(
                            $maximumDateTime->format('Y-m-d H:i'),
                            $timeZone
                        ) : $endDateTime;
                }

                $endDateTime->setTimezone(DateTimeService::getTimeZone());

                GoogleCalendarService::$providersGoogleEvents = [];

                OutlookCalendarService::$providersOutlookEvents = [];

                $freeSlots = $applicationTimeSlotService->getSlotsByProps(
                    $settings,
                    array_merge(
                        $props,
                        [
                            'startDateTime' => $startDateTime,
                            'endDateTime'   => $endDateTime,
                        ]
                    ),
                    $filteredSlotEntities
                );

                if ($endDateTime->format('Y-m-d H:i') === $maximumDateTime->format('Y-m-d H:i') ||
                    $endDateTime > $maximumDateTime
                ) {
                    break;
                }
            }

            // search once more if first available date is in 11 days added to endDateTime (days outside calendar on frontend form)
            foreach (array_slice($freeSlots['available'], 0, 1, true) as $slotDate => $slotTimes) {
                if (substr($slotDate, 0, 7) === $endDateTime->format('Y-m')) {
                    $endDateTime->modify('last day of this month');

                    $endDateTime->modify('+12days');

                    $endDateTime->setTime(23, 59, 59);

                    if ($isFrontEndBooking) {
                        $endDateTime = $endDateTime > $maximumDateTime ?
                            DateTimeService::getDateTimeObjectInTimeZone(
                                $maximumDateTime->format('Y-m-d H:i'),
                                $timeZone
                            ) : $endDateTime;
                    }

                    GoogleCalendarService::$providersGoogleEvents = [];

                    OutlookCalendarService::$providersOutlookEvents = [];

                    $freeSlots = $applicationTimeSlotService->getSlotsByProps(
                        $settings,
                        array_merge(
                            $props,
                            [
                                'startDateTime' => $startDateTime,
                                'endDateTime'   => $endDateTime,
                            ]
                        ),
                        $filteredSlotEntities
                    );
                }
            }
        }

        $busyness = [];

        foreach ($freeSlots['available'] as $slotDate => $slotTimes) {
            if (!empty($freeSlots['continuousAppointments'][$slotDate]) && !empty($freeSlots['occupied'][$slotDate])) {
                $freeSlots['occupied'][$slotDate] =
                    array_merge(
                        $freeSlots['occupied'][$slotDate],
                        $freeSlots['continuousAppointments'][$slotDate]
                    );

                foreach ($freeSlots['occupied'][$slotDate] as $key => $timeKey) {
                    $freeSlots['occupied'][$slotDate][$key] =
                        [0 => reset($freeSlots['occupied'][$slotDate])[0]];
                }
            }

            $busyness[$slotDate] = round(
                count(!empty($freeSlots['occupied'][$slotDate]) ? $freeSlots['occupied'][$slotDate] : []) /
                (count(!empty($freeSlots['available'][$slotDate]) ? $freeSlots['available'][$slotDate] : []) +
                    count(!empty($freeSlots['occupied'][$slotDate]) ? $freeSlots['occupied'][$slotDate] : []))
                * 100);
        }

        $converted = ['available' => [], 'occupied' => []];

        $isUtcResponse = ($settingsDS->getSetting('general', 'showClientTimeZone') && $isFrontEndBooking) ||
            $command->getField('timeZone');

        if ($isUtcResponse) {
            foreach (['available', 'occupied'] as $type) {
                foreach ($freeSlots[$type] as $slotDate => $slotTimes) {
                    foreach ($freeSlots[$type][$slotDate] as $slotTime => $slotTimesProviders) {
                        $convertedSlotParts = explode(
                            ' ',
                            $command->getField('timeZone') ?
                                DateTimeService::getCustomDateTimeObjectInTimeZone(
                                    $slotDate . ' ' . $slotTime,
                                    $command->getField('timeZone')
                                )->format('Y-m-d H:i') :
                                DateTimeService::getCustomDateTimeObjectInUtc(
                                    $slotDate . ' ' . $slotTime
                                )->format('Y-m-d H:i')
                        );

                        $converted[$type][$convertedSlotParts[0]][$convertedSlotParts[1]] = $slotTimesProviders;
                    }
                }
            }
        }

        if (count($props['providerIds']) !== 1 && $settingsDS->getSetting('appointments', 'employeeSelection') === 'roundRobin') {
            /** @var AppointmentRepository $appointmentRepository */
            $appointmentRepository = $this->container->get('domain.booking.appointment.repository');

            $lastBookedProviderId = $appointmentRepository->getLastBookedEmployee($props['providerIds']);
        }

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully retrieved free slots');
        $result->setData(
            [
                'minimum' => $isUtcResponse ?
                    $minimumDateTime->setTimezone(
                        new DateTimeZone('UTC')
                    )->format('Y-m-d H:i') : $minimumDateTime->format('Y-m-d H:i'),
                'maximum'   => $isUtcResponse ?
                    $maximumDateTime->setTimezone(
                        new DateTimeZone('UTC')
                    )->format('Y-m-d H:i') : $maximumDateTime->format('Y-m-d H:i'),
                'slots'     => $converted['available'] ?: $freeSlots['available'],
                'occupied'  => $converted['occupied'] ?: $freeSlots['occupied'],
                'busyness'  => $busyness,
                'lastProvider' => $lastBookedProviderId
            ]
        );

        return $result;
    }
}
