<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Form;

use Pixiekat\LuminaUiBundle\Entity;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type as FormTypes;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class AdminCreateUserFormType extends AbstractType {
  public function buildForm(FormBuilderInterface $builder, array $options): void {
    $builder
      ->add('email', FormTypes\EmailType::class, [
        'label' => 'Email Address',
        'constraints' => [
          new Assert\NotBlank(),
          new Assert\Email(),
        ],
      ])
      ->add('password', FormTypes\PasswordType::class, [
        'label' => 'Password',
        'constraints' => [
          new Assert\NotBlank(),
          new Assert\Length(
            min: 6,
            max: 4096,
            minMessage: 'Your password should be at least {{ limit }} characters.',
          ),
        ],
        // don't map the password field to the User entity, because we will hash it before saving
        'mapped' => false,
      ])

      # also confirm the password and asset that the two passwords match
      ->add('confirm_password', FormTypes\PasswordType::class, [
        'label' => 'Confirm Password',
        'mapped' => false,
        'constraints' => [
          new Assert\NotBlank(),
        ],
      ])
      ->add('roles', FormTypes\ChoiceType::class, [
        'choices' => [
          'Admin' => 'ROLE_ADMIN',
          'Evaluator' => 'ROLE_EVALUATOR',
        ],
        'multiple' => true,
        'expanded' => true,
      ])
      ->add('active', FormTypes\CheckboxType::class, [
        'label' => 'Active',
      ])
    ;

    /**
     * Add a post-submit listener to check that the password and confirm password fields match.
     */
    $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
      $form = $event->getForm();

      $password = (string)$form->get('password')->getData();
      $confirmPassword = (string)$form->get('confirm_password')->getData();

      // Let NotBlank speak first if either box is empty.
      if ($password === '' || $confirmPassword === '') {
        return;
      }

      if ($password !== $confirmPassword) {
        $form->get('confirm_password')->addError(
          new FormError('Passwords do not match.')
        );
      }
    });
  }

  public function configureOptions(OptionsResolver $resolver): void {
    $resolver->setDefaults([
      'data_class' => Entity\User::class,
    ]);
  }
}
