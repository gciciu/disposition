<?php

namespace App\DataFixtures;

use App\Entity\Courier;
use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $users = [
            [
                'email' => 'superadmin@delivery.local',
                'name' => 'Administrator',
                'password' => 'SuperAdmin123!',
                'roles' => [UserRole::SuperAdmin->value],
                'isCourier' => false,
            ],
            [
                'email' => 'admin@delivery.local',
                'name' => 'Админ',
                'password' => 'Admin123!',
                'roles' => [UserRole::Admin->value],
                'isCourier' => false,
            ],
            [
                'email' => 'courier@delivery.local',
                'name' => 'Курьер',
                'password' => 'Courier123!',
                'roles' => [UserRole::Courier->value],
                'isCourier' => true,
            ],
        ];

        foreach ($users as $data) {
            $user = (new User())
                ->setEmail($data['email'])
                ->setName($data['name'])
                ->setRoles($data['roles'])
                ->setMustChangePassword($data['isCourier']);

            $user->setPassword($this->passwordHasher->hashPassword($user, $data['password']));
            $manager->persist($user);

            if ($data['isCourier']) {
                $courier = (new Courier())->setUser($user);
                $user->setCourier($courier);
                $manager->persist($courier);
            }
        }

        $manager->flush();
    }
}
