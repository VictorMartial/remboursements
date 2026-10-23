<?php

namespace App\Repository;

use App\Entity\AppSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AppSetting>
 */
class AppSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppSetting::class);
    }

    /**
     * Récupère la valeur d'un paramètre (ou une valeur par défaut).
     */
    public function getValue(string $key, ?string $default = null): ?string
    {
        $setting = $this->findOneBy(['settingKey' => $key]);
        return $setting?->getSettingValue() ?? $default;
    }

    /**
     * Crée ou met à jour un paramètre et le persiste en base.
     */
    public function setValue(string $key, ?string $value): AppSetting
    {
        $setting = $this->findOneBy(['settingKey' => $key]);
        if (!$setting) {
            $setting = new AppSetting($key, $value);
            $this->getEntityManager()->persist($setting);
        } else {
            $setting->setSettingValue($value);
        }
        $this->getEntityManager()->flush();
        return $setting;
    }
}
