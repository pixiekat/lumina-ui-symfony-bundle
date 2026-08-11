<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\LuminaUiBundle\Entity;
use Pixiekat\LuminaUiBundle\Form;
use Pixiekat\LuminaUiBundle\Interfaces as PixieInterfaces;
use Pixiekat\LuminaUiBundle\Repository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(PixieInterfaces\Security\Voter\GenericVoterInterface::CAN_ACCESS_ADMIN_PANEL)]
#[Route('/admin')]
final class AdminController extends AbstractController {

  public function __construct(
    private readonly EntityManagerInterface $entityManager,
    private readonly RequestStack $requestStack,
    private readonly UserPasswordHasherInterface $passwordHasher,
  ) {  }

  #[Route('/', name: 'admin_index')]
  public function index(): Response {
    return $this->render('@LuminaUi/admin/admin_index.html.twig');
  }

  #[Route('/users', name: 'admin_users_list')]
  public function listUsers(): Response {
    $users = $this->entityManager->getRepository(Entity\User::class)->findAll();
    return $this->render('@LuminaUi/admin/users_list.html.twig', [
      'users' => $users,
    ]);
  }

  #[Route('/user/add', name: 'admin_user_add')]
  public function addUser(): Response {

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

  /**
   * Deletes a user.
   *
   * ── The CSRF check below was missing ──────────────────────────────────────
   * users_list.html.twig has always POSTed a `delete-user-<id>` token, but this
   * method never looked at it, so the protection was decorative: any page on any
   * site could auto-submit a form to this URL and an admin who happened to be
   * logged in would delete that user without ever seeing it. POST alone stops
   * nothing — a cross-site form POST carries the session cookie exactly like a
   * same-site one does. Restricting the route to POST prevents accidents (a
   * crawler, a prefetch); only the token prevents an attack.
   *
   * Every other write path in this bundle validates its token; this one now
   * matches them, and the template needed no change because the token was
   * already there.
   */
  #[Route('/user/{id}/delete', name: 'admin_user_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
  public function deleteUser(int $id, Request $request): Response {
    $user = $this->entityManager->getRepository(Entity\User::class)->find($id);
    if (!$user) {
      throw $this->createNotFoundException('User not found');
    }

    if (!$this->isCsrfTokenValid('delete-user-' . $id, (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $this->redirectToRoute('admin_users_list');
    }

    $this->entityManager->remove($user);
    $this->entityManager->flush();

    $this->addFlash('success', 'User deleted successfully.');
    return $this->redirectToRoute('admin_users_list');
  }

  // ───────────────────────────────────────────────────────────────────────────
  // Evaluator reviews
  //
  // Two screens: every review ever recorded, and the editable list of reasons
  // offered when someone disagrees. Both are admin-only by virtue of the
  // class-level #[IsGranted(CAN_ACCESS_ADMIN_PANEL)] and the /admin prefix.
  //
  // Repositories arrive as ACTION arguments rather than constructor properties,
  // deliberately: the user-management actions above are the common case for this
  // controller and have no use for them. Symfony autowires action arguments for
  // controllers registered as services, which the bundle's services.php arranges
  // through autoconfigure.
  // ───────────────────────────────────────────────────────────────────────────

  /**
   * Every evaluator review in the system — the full table.
   *
   * Paginated rather than a bare findAll(): this table grows with every review
   * of every evaluation, so it is the one review screen with no natural bound.
   * The repository eagerly joins reviewer, reason and evaluation, so drawing a
   * page of 50 rows is one query rather than 151.
   */
  #[Route('/reviews', name: 'admin_reviews_list', methods: ['GET'])]
  public function listReviews(
    Repository\EvaluationReviewRepository $reviews,
    Request $request,
  ): Response {
    $perPage = 50;
    $page = max(1, $request->query->getInt('page', 1));
    $paginator = $reviews->paginateAll($page, $perPage);
    $total = count($paginator);

    return $this->render('@LuminaUi/admin/reviews_list.html.twig', [
      'reviews' => $paginator,
      'page' => $page,
      'pages' => max(1, (int) ceil($total / $perPage)),
      'total' => $total,
    ]);
  }

  /**
   * The editable list of "why do you disagree?" reasons.
   *
   * A single screen doing list + add + edit, rather than the add/edit page pair
   * the user admin above uses. The difference is the shape of the data: a reason
   * is three short fields, so putting every row's form on one page means an
   * admin can reword all of them and reorder the list without a round trip per
   * row. It stays a plain form POST per row, so it works with no JavaScript.
   *
   * Plain POST + CSRF here rather than a Symfony FormType, matching
   * EvaluationController rather than the user screens: a FormType earns its
   * keep when there is validation to declare and errors to render per field, and
   * here there is one rule ("a reason needs some text").
   */
  #[Route('/review-questions', name: 'admin_review_questions', methods: ['GET'])]
  public function listReviewQuestions(
    Repository\ReviewQuestionRepository $questions,
    Repository\EvaluationReviewRepository $reviews,
  ): Response {
    return $this->render('@LuminaUi/admin/review_questions.html.twig', [
      // findAllOrdered, not findActiveOrdered: an admin has to see retired
      // reasons in order to be able to bring one back.
      'questions' => $questions->findAllOrdered(),
      // "Used by 4 reviews" next to each one. Fetched as a single grouped query
      // keyed by question id, so the page does not run one COUNT per row.
      'usage' => $reviews->countByQuestion(),
    ]);
  }

  /** Adds a new reason to the bottom of the list. */
  #[Route('/review-questions/add', name: 'admin_review_question_add', methods: ['POST'])]
  public function addReviewQuestion(Request $request): Response {
    if (!$this->isCsrfTokenValid('review-question-add', (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $this->redirectToRoute('admin_review_questions');
    }

    $label = trim((string) $request->request->get('label'));
    if ($label === '') {
      $this->addFlash('error', 'A reason needs some text before it can be added.');
      return $this->redirectToRoute('admin_review_questions');
    }

    $question = new Entity\ReviewQuestion($label, $request->request->getInt('weight'));
    $description = trim((string) $request->request->get('description'));
    $question->setDescription($description === '' ? null : $description);

    $this->entityManager->persist($question);
    $this->entityManager->flush();

    $this->addFlash('success', sprintf('Reason "%s" added.', $label));

    return $this->redirectToRoute('admin_review_questions');
  }

  /**
   * Saves one row of the reasons table: text, help text, order, active flag.
   *
   * ── Why there is no delete ─────────────────────────────────────────────────
   * A reason that has been chosen is part of the record of why somebody
   * disagreed with an evaluation. Deactivating removes it from the dropdown for
   * new reviews while every existing review keeps reading correctly — which is
   * what "we don't use that reason any more" actually means. The foreign key is
   * ON DELETE SET NULL as a safety net for a row deleted outside the app, but
   * the app never offers to do it.
   */
  #[Route('/review-questions/{id}', name: 'admin_review_question_save', requirements: ['id' => '\d+'], methods: ['POST'])]
  public function saveReviewQuestion(int $id, Request $request): Response {
    $question = $this->entityManager->getRepository(Entity\ReviewQuestion::class)->find($id);
    if (!$question) {
      throw $this->createNotFoundException(sprintf('Reason #%d not found.', $id));
    }

    if (!$this->isCsrfTokenValid('review-question-' . $id, (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $this->redirectToRoute('admin_review_questions');
    }

    $label = trim((string) $request->request->get('label'));
    if ($label === '') {
      $this->addFlash('error', 'A reason cannot be saved with no text. Deactivate it instead if it is no longer wanted.');
      return $this->redirectToRoute('admin_review_questions');
    }

    $description = trim((string) $request->request->get('description'));

    $question
      ->setLabel($label)
      ->setDescription($description === '' ? null : $description)
      ->setWeight($request->request->getInt('weight'))
      // An unchecked checkbox sends nothing at all, so absence IS the "off"
      // signal here — `has()` is the check, not a cast of the value.
      ->setActive($request->request->has('active'));

    $this->entityManager->flush();

    $this->addFlash('success', sprintf('Reason "%s" saved.', $label));

    return $this->redirectToRoute('admin_review_questions');
  }
}
