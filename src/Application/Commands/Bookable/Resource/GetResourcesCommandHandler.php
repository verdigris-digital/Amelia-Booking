<?php
/**
 * @copyright © TMS-Plugins. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Application\Commands\Bookable\Resource;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Domain\Collection\Collection;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\Bookable\Service\ResourceRepository;
use Interop\Container\Exception\ContainerException;
use Slim\Exception\ContainerValueNotFoundException;

/**
 * Class GetResourcesCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Bookable\Resource
 */
class GetResourcesCommandHandler extends CommandHandler
{
    /**
     * @param GetResourcesCommand $command
     *
     * @return CommandResult
     * @throws ContainerValueNotFoundException
     * @throws QueryExecutionException
     * @throws InvalidArgumentException
     * @throws AccessDeniedException
     * @throws ContainerException
     */
    public function handle(GetResourcesCommand $command)
    {
        if (!$this->getContainer()->getPermissionsService()->currentUserCanRead(Entities::RESOURCES)) {
            throw new AccessDeniedException('You are not allowed to read resources.');
        }

        $result = new CommandResult();

        $this->checkMandatoryFields($command);

        $params = $command->getField('params');

        /** @var ResourceRepository $resourceRepository */
        $resourceRepository = $this->container->get('domain.bookable.resource.repository');

        /** @var Collection $resources */
        $resources = $resourceRepository->getByCriteria(['search' => $params['search']]);

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully retrieved resources.');
        $result->setData(
            [
                Entities::RESOURCES => $resources->toArray(),
            ]
        );

        return $result;
    }
}
