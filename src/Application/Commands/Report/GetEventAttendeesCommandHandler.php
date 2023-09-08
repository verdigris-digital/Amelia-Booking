<?php
/**
 * @copyright © TMS-Plugins. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Application\Commands\Report;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Application\Services\Helper\HelperService;
use AmeliaBooking\Application\Services\Payment\PaymentApplicationService;
use AmeliaBooking\Domain\Collection\Collection;
use AmeliaBooking\Domain\Entity\Booking\Appointment\CustomerBooking;
use AmeliaBooking\Domain\Entity\Booking\Event\CustomerBookingEventTicket;
use AmeliaBooking\Domain\Entity\Booking\Event\Event;
use AmeliaBooking\Domain\Entity\CustomField\CustomField;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Domain\Entity\Payment\Payment;
use AmeliaBooking\Domain\Entity\User\AbstractUser;
use AmeliaBooking\Domain\Services\DateTime\DateTimeService;
use AmeliaBooking\Domain\Services\Report\ReportServiceInterface;
use AmeliaBooking\Domain\Services\Settings\SettingsService;
use AmeliaBooking\Domain\ValueObjects\String\BookingStatus;
use AmeliaBooking\Infrastructure\Repository\Booking\Event\CustomerBookingEventTicketRepository;
use AmeliaBooking\Infrastructure\Repository\Booking\Event\EventRepository;
use AmeliaBooking\Infrastructure\Repository\Booking\Event\EventTicketRepository;
use AmeliaBooking\Infrastructure\Repository\CustomField\CustomFieldRepository;
use AmeliaBooking\Infrastructure\WP\Translations\BackendStrings;

/**
 * Class GetCustomersCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Report
 */
class GetEventAttendeesCommandHandler extends CommandHandler
{
    /**
     * @param GetEventAttendeesCommand $command
     *
     * @return CommandResult
     * @throws \Slim\Exception\ContainerValueNotFoundException
     * @throws AccessDeniedException
     * @throws \AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException
     * @throws \AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException
     * @throws \Interop\Container\Exception\ContainerException
     */
    public function handle(GetEventAttendeesCommand $command)
    {
        if (!$this->getContainer()->getPermissionsService()->currentUserCanRead(Entities::APPOINTMENTS)) {
            throw new AccessDeniedException('You are not allowed to read appointments.');
        }

        $result = new CommandResult();

        $this->checkMandatoryFields($command);

        $params = $command->getField('params');

        /** @var CustomFieldRepository $customFieldRepository */
        $customFieldRepository = $this->container->get('domain.customField.repository');

        /** @var Collection $customFieldsList */
        $customFieldsList = $customFieldRepository->getAll();

        /** @var EventRepository $eventRepository */
        $eventRepository = $this->container->get('domain.booking.event.repository');

        /** @var Event $event */
        $event = $eventRepository->getById((int)$params['id']);

        /** @var ReportServiceInterface $reportService */
        $reportService = $this->container->get('infrastructure.report.csv.service');

        /** @var SettingsService $settingsDomainService */
        $settingsDomainService = $this->container->get('domain.settings.service');

        $rows = [];

        $fields = $command->getField('params')['fields'];

        $delimiter = $command->getField('params')['delimiter'];

        $dateFormat = $settingsDomainService->getSetting('wordpress', 'dateFormat');

        $row   = [];
        $rowCF = [];

        $bookingKeys = array_keys($event->getBookings()->getItems());
        $lastIndex   = end($bookingKeys);

        $delimiterSeparate = $params['separate'] === 'true' ? '' : ', ';

        /** @var CustomerBooking $booking */
        foreach ($event->getBookings()->getItems() as $index => $booking) {
            /** @var AbstractUser $customer */
            $customer = $booking->getCustomer();

            if ($params['separate'] === 'true') {
                $row   = [];
                $rowCF = [];
            }

            $customFieldsJson = $booking->getCustomFields() ?
                json_decode($booking->getCustomFields()->getValue(), true) : [];


            foreach ((array)$customFieldsJson as $customFieldId => $customFiled) {
                /** @var Collection $customFieldEvents */
                $customFieldEvents = $customFieldsList->keyExists($customFieldId) && $customFieldsList->getItem($customFieldId)->getEvents() ? $customFieldsList->getItem($customFieldId)->getEvents(): new Collection();

                $eventHasCustomField = false;

                if ($customFieldsList->keyExists($customFieldId) && $customFieldsList->getItem($customFieldId)->getAllEvents() && $customFieldsList->getItem($customFieldId)->getAllEvents()->getValue()) {
                    $eventHasCustomField = true;
                } else {
                    /** @var Event $customFieldEvent */
                    foreach ($customFieldEvents->getItems() as $customFieldEvent) {
                        if ($customFieldEvent->getId()->getValue() === (int)$params['id']) {
                            $eventHasCustomField = true;
                            break;
                        }
                    }
                }


                if ((array_key_exists('type', $customFiled) && $customFiled['type'] === 'file') ||
                    !$eventHasCustomField
                ) {
                    continue;
                }

                /** @var CustomField $item **/
                $item = $customFieldsList->keyExists($customFieldId) ? $customFieldsList->getItem($customFieldId) : null;
                if ($item) {
                    if (is_array($customFiled['value'])) {
                        $rowCF[$item->getLabel()->getValue()] .= implode('|', $customFiled['value']) . $delimiterSeparate;
                    } else {
                        $rowCF[$item->getLabel()->getValue()] .= $customFiled['value'] . $delimiterSeparate;
                    }
                }
            }

            $infoJson = $booking->getInfo() ? json_decode($booking->getInfo()->getValue(), true) : null;

            $customerInfo = $infoJson ?: $customer->toArray();

            if (in_array('firstName', $fields, true)) {
                $row[BackendStrings::getUserStrings()['first_name']] .= $customerInfo['firstName'] . $delimiterSeparate;
            }

            if (in_array('lastName', $fields, true)) {
                $row[BackendStrings::getUserStrings()['last_name']] .= $customerInfo['lastName'] . $delimiterSeparate;
            }

            if (in_array('email', $fields, true)) {
                $row[BackendStrings::getUserStrings()['email']] .=
                    ($customer->getEmail() ? $customer->getEmail()->getValue() : '') . $delimiterSeparate;
            }

            $phone = $customer->getPhone() ? $customer->getPhone()->getValue() : '';

            if (in_array('phone', $fields, true)) {
                $row[BackendStrings::getCommonStrings()['phone']] .=
                    ($customerInfo['phone'] ?: $phone) . $delimiterSeparate;
            }

            if (in_array('gender', $fields, true)) {
                $row[BackendStrings::getCustomerStrings()['gender']] .=
                    ($customer->getGender() ? $customer->getGender()->getValue() : '') . $delimiterSeparate;
            }

            if (in_array('birthday', $fields, true)) {
                $row[BackendStrings::getCustomerStrings()['date_of_birth']] .=
                    ($customer->getBirthday() ?
                        DateTimeService::getCustomDateTimeObject($customer->getBirthday()->getValue()->format('Y-m-d'))
                        ->format($dateFormat) : '') . $delimiterSeparate;
            }

            /** @var HelperService $helperService */
            $helperService = $this->container->get('application.helper.service');
            if (in_array('paymentAmount', $fields, true)) {
                $payments = $booking->getPayments() && $booking->getPayments()->length() > 0 ?
                    $booking->getPayments()->toArray() : null;
                $amount   = !empty($payments) ? array_sum(array_column($payments, 'amount')) : 0;
                $row[BackendStrings::getCommonStrings()['payment_amount']] .= $helperService->getFormattedPrice($amount) . $delimiterSeparate;
            }

            if (in_array('paymentStatus', $params['fields'], true)) {
                /** @var PaymentApplicationService $paymentAS */
                $paymentAS     = $this->container->get('application.payment.service');
                $paymentStatus = $paymentAS->getFullStatus($booking->toArray(), Entities::EVENT);
                $row[BackendStrings::getCommonStrings()['payment_status']] .= ($paymentStatus === 'partiallyPaid' ? BackendStrings::getCommonStrings()['partially_paid'] : BackendStrings::getCommonStrings()[$paymentStatus]) . $delimiterSeparate;
            }

            if (in_array('paymentMethod', $params['fields'], true)) {
                $payments    = $booking->getPayments() && $booking->getPayments()->length() > 0 ?
                    $booking->getPayments()->toArray() : null;
                $methodsUsed = array_map(
                    function ($payment) {
                        $method = $payment['gateway'];
                        if ($method === 'wc') {
                            $method = 'wc_name';
                        }
                        return !$method || $method === 'onSite' ? BackendStrings::getCommonStrings()['on_site'] : BackendStrings::getSettingsStrings()[$method];
                    },
                    $payments
                );
                $row[BackendStrings::getCommonStrings()['payment_method']] .= (count(array_unique($methodsUsed)) === 1 ? $methodsUsed[0] : implode((empty($delimiterSeparate) ? ', ' : '/'), $methodsUsed)) . $delimiterSeparate;
            }

            if (in_array('wcOrderId', $params['fields'], true)) {
                /** @var Payment $payment */
                $payments  = $booking->getPayments() && $booking->getPayments()->length() > 0 ?
                    $booking->getPayments()->toArray() : null;
                $wcOrderId = implode((empty($delimiterSeparate) ? ', ' : '/'), array_column($payments, 'wcOrderId'));
                $row[BackendStrings::getCommonStrings()['wc_order_id_export']] .= $wcOrderId . $delimiterSeparate;
            }

            if (in_array('note', $fields, true)) {
                $row[BackendStrings::getCustomerStrings()['customer_note']] .=
                    ($customer->getNote() ? $customer->getNote()->getValue() : '') . $delimiterSeparate;
            }

            /** @var CustomerBookingEventTicketRepository $bookingEventTicketRepository */
            $bookingEventTicketRepository =
                $this->container->get('domain.booking.customerBookingEventTicket.repository');

            // get all ticket bookings by customerBookingId
            $ticketsBookings = $bookingEventTicketRepository->getByEntityId(
                $booking->getId()->getValue(),
                'customerBookingId'
            );

            if (in_array('persons', $fields, true)) {
                $persons = $booking->getPersons()->getValue();
                if ($event->getCustomPricing() && $event->getCustomPricing()->getValue()) {
                    $persons = 0;
                    /** @var CustomerBookingEventTicket $bookingToEventTicket */
                    foreach ($ticketsBookings->getItems() as $bookingToEventTicket) {
                        $persons += $bookingToEventTicket->getPersons() ? $bookingToEventTicket->getPersons()->getValue() : 0;
                    }
                }
                $row[BackendStrings::getEventStrings()['event_book_persons']] .= $persons . $delimiterSeparate;
            }

            if (in_array('status', $fields, true)) {
                $row[BackendStrings::getEventStrings()['event_book_status']] .= ucfirst($booking->getStatus()->getValue()) . $delimiterSeparate;
            }

            if (in_array('tickets', $fields, true)) {
                if (count($ticketsBookings->getItems())) {
                    $ticketsExportString = '';
                    /** @var EventTicketRepository $eventTicketRepository */
                    $eventTicketRepository = $this->container->get('domain.booking.event.ticket.repository');
                    /** @var CustomerBookingEventTicket $bookingToEventTicket */
                    foreach ($ticketsBookings->getItems() as $key => $bookingToEventTicket) {
                        $ticket = $eventTicketRepository->getById($bookingToEventTicket->getEventTicketId()->getValue());

                        $ticketsExportString .= $bookingToEventTicket->getPersons()->getValue() . ' x ' . $ticket->getName()->getValue() .
                            ($key !== count($ticketsBookings->getItems()) - 1 ? ', ' : '');
                    }
                    if (empty($row[BackendStrings::getEventStrings()['event_book_tickets']])) {
                        $row[BackendStrings::getEventStrings()['event_book_tickets']] = '';
                    }

                    $row[BackendStrings::getEventStrings()['event_book_tickets']] .= $ticketsExportString . ($params['separate'] === 'true' ? '' : '; ');
                }
            }

            $mergedRow = array_merge($row, $rowCF);

            $mergedRow = apply_filters('amelia_before_csv_export_event', $mergedRow, $event->toArray(), $params['separate']);

            if ($params['separate'] === 'true') {
                $rows[] = $mergedRow;
            } else if ($index === $lastIndex) {
                $finalRow = $mergedRow;
                $rows[]   = array_map(
                    function ($item) {
                        return substr($item, 0, -2);
                    },
                    $finalRow
                );
            }
        }

        $reportService->generateReport($rows, str_replace(' ', '_', $event->getName()->getValue()), $delimiter);

        $result->setAttachment(true);

        return $result;
    }
}
