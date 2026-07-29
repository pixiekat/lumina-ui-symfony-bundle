<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\LuminaUiBundle\Entity;
use Pixiekat\LuminaUiBundle\Form;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
final class AdminController extends AbstractController {

  public function __construct(
    private readonly EntityManagerInterface $entityManager,
    private readonly RequestStack $requestStack,
    private readonly UserPasswordHasherInterface $passwordHasher,
  ) {  }

  #[Route('/users', name: 'admin_users_list')]
  public function listUsers(): Response {
    $users = $this->entityManager->getRepository(Entity\User::class)->findAll();
    return $this->render('@LuminaUi/admin/users_list.html.twig', [
      'users' => $users,
    ]);
  }

  #[Route('/user/add', name: 'admin_user_add')]
  public function index(): Response {

    $user = new Entity\User();
    $form = $this->createForm(Form\AdminCreateUserFormType::class, $user);
    $form->handleRequest($this->requestStack->getCurrentRequest());

    // process the form since we assume all passed validation in the form type.
    if ($form->isSubmitted() && $form->isValid()) {

      // is active checked, if so set the user to active
      // normalise to boolean first.
      $active = (bool)$form->get('active')->getData();
      $user->setActive($active);

      // our roles are ROLE_ADMIN and ROLE_EVALUATOR, multiple select. if any are checked, add the roles to the user. ROLE USER is always added by default.
      $roles = $form->get('roles')->getData();
      if (is_array($roles)) {
        $user->setRoles($roles);
      }

      // obviously we're adding a user so we always have a password.
      // hash it and set the password on the user entity.
      $plainPassword = (string)$form->get('password')->getData();
      $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
      $user->setPassword($hashedPassword);

      $this->entityManager->persist($user);
      $this->entityManager->flush();

      $this->addFlash('success', 'User created successfully.');

      return $this->redirectToRoute('admin_users_list');
    }

    return $this->render('@LuminaUi/admin/user_add.html.twig', [
      'form' => $form->createView(),
    ]);
  }

  #[Route('/user/{id}/edit', name: 'admin_user_edit', requirements: ['id' => '\d+'])]
  public function editUser(int $id): Response {
    $user = $this->entityManager->getRepository(Entity\User::class)->find($id);
    if (!$user) {
      throw $this->createNotFoundException('User not found');
    }

    $form = $this->createForm(Form\AdminEditUserFormType::class, $user);
    $form->handleRequest($this->requestStack->getCurrentRequest());

    // process the form since we assume all passed validation in the form type.
    if ($form->isSubmitted() && $form->isValid()) {

      // passwords are cast to string in the form type listener, so we'll just see if we need to
      // hash based on whether the string is empty or not. If it's empty, the admin didn't want to change it.
      $plainPassword = (string)$form->get('plainPassword')->getData();
      if ($plainPassword !== '') {
        $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
        $user->setPassword($hashedPassword);
      }

      // is active checked, if so set the user to active
      // normalise to boolean first.
      $active = (bool)$form->get('active')->getData();
      $user->setActive($active);

      // our roles are ROLE_ADMIN and ROLE_EVALUATOR, multiple select. if any are checked, add the roles to the user. ROLE USER is always added by default.
      $roles = $form->get('roles')->getData();
      if (is_array($roles)) {
        $user->setRoles($roles);
      }

      $this->entityManager->flush();

      $this->addFlash('success', $plainPassword !== ''
        ? 'User updated and password changed.'
        : 'User updated. Password left unchanged.');

      return $this->redirectToRoute('admin_users_list');
    }

    return $this->render('@LuminaUi/admin/user_edit.html.twig', [
      'form' => $form->createView(),
      'user' => $user,
    ]);
  }

  #[Route('/user/{id}/delete', name: 'admin_user_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
  public function deleteUser(int $id): Response {
    $user = $this->entityManager->getRepository(Entity\User::class)->find($id);
    if (!$user) {
      throw $this->createNotFoundException('User not found');
    }

    $this->entityManager->remove($user);
    $this->entityManager->flush();

    $this->addFlash('success', 'User deleted successfully.');
    return $this->redirectToRoute('admin_users_list');
  }
}
