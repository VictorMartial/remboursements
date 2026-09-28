<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/admin/users')]
#[IsGranted('ROLE_ADMIN')]
class UserApiController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepo,
        private UserPasswordHasherInterface $passwordHasher,
        private ValidatorInterface $validator
    ) {
    }


    /**
     * Liste des utilisateurs
     */
    #[Route('', name: 'api_users_list', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $users = $this->userRepo->findAll();

        return $this->json([
            'success' => true,
            'users' => array_map(
                fn(User $user) => $this->serializeUser($user),
                $users
            )
        ]);
    }


    /**
     * Afficher un utilisateur
     */
    #[Route('/{id}', name: 'api_users_show', methods: ['GET'])]
    public function show(User $user): JsonResponse
    {
        return $this->json([
            'success' => true,
            'user' => $this->serializeUser($user)
        ]);
    }


    /**
     * Créer un utilisateur
     */
    #[Route('', name: 'api_users_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);


        if (!$data) {
            return $this->json([
                'success' => false,
                'message' => 'Données JSON invalides'
            ], Response::HTTP_BAD_REQUEST);
        }


        $user = new User();


        // Email
        $user->setEmail(
            $data['email'] ?? ''
        );


        // Role unique
        $user->setRole(
            $data['role'] ?? 'ROLE_USER'
        );


        // Mot de passe temporaire
        $plainPassword = $data['password'] ?? '';

        $user->setPlainPassword($plainPassword);



        // Validation
        $errors = $this->validator->validate($user);


        if (count($errors) > 0) {

            $messages = [];

            foreach ($errors as $error) {
                $messages[] = $error->getMessage();
            }


            return $this->json([
                'success' => false,
                'errors' => $messages
            ], Response::HTTP_BAD_REQUEST);
        }



        // Hash password
        if (!empty($plainPassword)) {

            $hashedPassword = $this->passwordHasher->hashPassword(
                $user,
                $plainPassword
            );


            $user->setPassword($hashedPassword);

            $user->setPlainPassword(null);
        }



        $this->em->persist($user);
        $this->em->flush();



        return $this->json([
            'success' => true,
            'message' => 'Utilisateur créé avec succès',
            'user' => $this->serializeUser($user)
        ], Response::HTTP_CREATED);
    }



    /**
     * Modifier un utilisateur
     */
    #[Route('/{id}', name: 'api_users_update', methods: ['PUT'])]
    public function update(
        Request $request,
        User $user
    ): JsonResponse {


        $data = json_decode($request->getContent(), true);



        if (isset($data['email'])) {

            $user->setEmail(
                $data['email']
            );
        }



        if (isset($data['role'])) {

            $user->setRole(
                $data['role']
            );
        }




        if (!empty($data['password'])) {


            $hashedPassword = $this->passwordHasher->hashPassword(
                $user,
                $data['password']
            );


            $user->setPassword($hashedPassword);

        }



        $errors = $this->validator->validate($user);



        if (count($errors) > 0) {


            $messages = [];

            foreach ($errors as $error) {
                $messages[] = $error->getMessage();
            }


            return $this->json([
                'success' => false,
                'errors' => $messages
            ], Response::HTTP_BAD_REQUEST);

        }



        $this->em->flush();



        return $this->json([
            'success' => true,
            'message' => 'Utilisateur modifié',
            'user' => $this->serializeUser($user)
        ]);

    }





    /**
     * Supprimer un utilisateur
     */
    #[Route('/{id}', name: 'api_users_delete', methods: ['DELETE'])]
    public function delete(User $user): JsonResponse
    {

        $this->em->remove($user);

        $this->em->flush();


        return $this->json([
            'success' => true,
            'message' => 'Utilisateur supprimé'
        ]);

    }





    private function serializeUser(User $user): array
    {

        return [

            'id' => $user->getId(),

            'email' => $user->getEmail(),

            'role' => $user->getRole(),

            'roles' => $user->getRoles(),

            'createdAt' =>
                $user->getCreatedAt()
                    ? $user->getCreatedAt()->format('Y-m-d H:i:s')
                    : null

        ];
    }
}