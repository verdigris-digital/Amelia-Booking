<?php

namespace AmeliaBooking\Application\Commands\Notification;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Services\Notification\WhatsAppNotificationService;
use AmeliaBooking\Domain\Services\Settings\SettingsService;
use Exception;
use Interop\Container\Exception\ContainerException;
use Slim\Exception\ContainerValueNotFoundException;

/**
 * Class WhatsAppWebhookCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Notification
 */
class WhatsAppWebhookCommandHandler extends CommandHandler
{

    /**
     * @param WhatsAppWebhookCommand $command
     *
     * @return CommandResult
     * @throws ContainerValueNotFoundException
     * @throws ContainerException
     * @throws Exception
     */
    public function handle(WhatsAppWebhookCommand $command)
    {
        $result = new CommandResult();

        /** @var SettingsService $settingsService */
        $settingsService = $this->container->get('domain.settings.service');

        $enabled = $settingsService->getSetting('notifications', 'whatsAppReplyEnabled');

        if (empty($enabled)) {
            $result->setResult(CommandResult::RESULT_SUCCESS);
            $result->setMessage('Auto-reply not enabled');
            $result->setData([]);

            return $result;
        }

        /** @var WhatsAppNotificationService $whatsAppNotificationService */
        $whatsAppNotificationService = $this->getContainer()->get('application.whatsAppNotification.service');

        $data = $command->getFields();

        $phones = [];
        foreach ($data['entry'] as $entry) {
            foreach ($entry['changes'] as $change) {
                if ($change['field'] === 'messages') {
                    foreach ($change['value']['messages'] as $message) {
                        $phones[] = $message['from'];
                        $whatsAppNotificationService->sendMessage($message['from']);
                    }
                }
            }
        }

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Auto-reply successfully sent');
        $result->setData($phones);

        return $result;
    }
}
