<?php
// src/Service/ActivityLogger.php
namespace App\Service;

use App\Entity\ActivityLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

class ActivityLogger
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private RequestStack $requestStack
    ) {}

    public function log(
        string $action,
        string $description,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $context = null
    ): void {
        $log = new ActivityLog();
        $log->setAction($action);
        $log->setDescription($description);
        $log->setEntityType($entityType);
        $log->setEntityId($entityId);
        $log->setContext($context);

        $user = $this->security->getUser();
        if ($user instanceof User) {
            $log->setUser($user);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            $log->setIpAddress($request->getClientIp());
        }

        $this->em->persist($log);
        $this->em->flush();
    }
}