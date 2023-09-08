<?php
/**
 * Assembling domain services:
 * Instantiating domain services and injecting the Infrastructure layer implementations
 */

defined('ABSPATH') or die('No script kiddies please!');

/**
 * Permissions service
 *
 * @param $c
 *
 * @return \AmeliaBooking\Domain\Services\Permissions\PermissionsService
 */
$entries['domain.permissions.service'] = function ($c) {
    return new AmeliaBooking\Domain\Services\Permissions\PermissionsService(
        $c,
        new AmeliaBooking\Infrastructure\WP\PermissionsService\PermissionsChecker()
    );
};

/**
 * Appointment service
 *
 * @return \AmeliaBooking\Domain\Services\Booking\AppointmentDomainService
 */
$entries['domain.booking.appointment.service'] = function () {
    return new AmeliaBooking\Domain\Services\Booking\AppointmentDomainService();
};


/**
 * Event service
 *
 * @return \AmeliaBooking\Domain\Services\Booking\EventDomainService
 */
$entries['domain.booking.event.service'] = function () {
    return new AmeliaBooking\Domain\Services\Booking\EventDomainService();
};

/**
 * Settings service
 *
 * @return \AmeliaBooking\Domain\Services\Settings\SettingsService
 */
$entries['domain.settings.service'] = function () {
    return new AmeliaBooking\Domain\Services\Settings\SettingsService(
        new AmeliaBooking\Infrastructure\WP\SettingsService\SettingsStorage()
    );
};

/**
 * @return \AmeliaBooking\Domain\Services\Interval\IntervalService
 */
$entries['domain.interval.service'] = function () {
    return new AmeliaBooking\Domain\Services\Interval\IntervalService();
};

/**
 * @return \AmeliaBooking\Domain\Services\User\ProviderService
 */
$entries['domain.user.provider.service'] = function () {
    return new AmeliaBooking\Domain\Services\User\ProviderService(
        new AmeliaBooking\Domain\Services\Interval\IntervalService()
    );
};

/**
 * @return \AmeliaBooking\Domain\Services\Location\LocationService
 */
$entries['domain.location.service'] = function () {
    return new AmeliaBooking\Domain\Services\Location\LocationService();
};

/**
 * @return \AmeliaBooking\Domain\Services\Schedule\ScheduleService
 */
$entries['domain.schedule.service'] = function () {
    $intervalService = new AmeliaBooking\Domain\Services\Interval\IntervalService();

    $locationService = new AmeliaBooking\Domain\Services\Location\LocationService();

    $providerService = new AmeliaBooking\Domain\Services\User\ProviderService(
        $intervalService
    );

    return new AmeliaBooking\Domain\Services\Schedule\ScheduleService(
        $intervalService,
        $providerService,
        $locationService
    );
};

/**
 * @return \AmeliaBooking\Domain\Services\Resource\AbstractResourceService
 */
$entries['domain.resource.service'] = function () {
    $intervalService = new AmeliaBooking\Domain\Services\Interval\IntervalService();

    $locationService = new AmeliaBooking\Domain\Services\Location\LocationService();

    $providerService = new AmeliaBooking\Domain\Services\User\ProviderService(
        $intervalService
    );

    $scheduleService = new AmeliaBooking\Domain\Services\Schedule\ScheduleService(
        $intervalService,
        $providerService,
        $locationService
    );

    // Change for Basic Licence - BasicResourceService
    return new AmeliaBooking\Domain\Services\Resource\ResourceService(
        $intervalService,
        $scheduleService
    );
};

/**
 * @return \AmeliaBooking\Domain\Services\Entity\EntityService
 */
$entries['domain.entity.service'] = function () {
    $intervalService = new AmeliaBooking\Domain\Services\Interval\IntervalService();

    $locationService = new AmeliaBooking\Domain\Services\Location\LocationService();

    $providerService = new AmeliaBooking\Domain\Services\User\ProviderService(
        $intervalService
    );

    $scheduleService = new AmeliaBooking\Domain\Services\Schedule\ScheduleService(
        $intervalService,
        $providerService,
        $locationService
    );

    // Change for Basic Licence - BasicResourceService
    $resourceService = new AmeliaBooking\Domain\Services\Resource\ResourceService(
        $intervalService,
        $scheduleService
    );

    return new AmeliaBooking\Domain\Services\Entity\EntityService(
        $providerService,
        $resourceService
    );
};

/**
 * @return \AmeliaBooking\Domain\Services\TimeSlot\TimeSlotService
 */
$entries['domain.timeSlot.service'] = function () {
    $intervalService = new AmeliaBooking\Domain\Services\Interval\IntervalService();

    $locationService = new AmeliaBooking\Domain\Services\Location\LocationService();

    $providerService = new AmeliaBooking\Domain\Services\User\ProviderService(
        $intervalService
    );

    $scheduleService = new AmeliaBooking\Domain\Services\Schedule\ScheduleService(
        $intervalService,
        $providerService,
        $locationService
    );

    // Change for Basic Licence - BasicResourceService
    $resourceService = new AmeliaBooking\Domain\Services\Resource\ResourceService(
        $intervalService,
        $scheduleService
    );

    $entityService = new AmeliaBooking\Domain\Services\Entity\EntityService(
        $providerService,
        $resourceService
    );

    return new AmeliaBooking\Domain\Services\TimeSlot\TimeSlotService(
        $intervalService,
        $scheduleService,
        $providerService,
        $resourceService,
        $entityService
    );
};
