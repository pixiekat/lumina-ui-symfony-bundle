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

class AdminEditUserFormType extends AbstractType {
  public function buildForm(FormBuilderInterface $builder, array $options): void {
    $builder
      ->add('email', FormTypes\EmailType::class, [
        'label' => 'Email Address',
        'constraints' => [
          new Assert\NotBlank(),
          new Assert\Email(),
        ],
      ])
      ->add('plainPassword', FormTypes\PasswordType::class, [
        'label' => 'Password',
        'constraints' => [
          new Assert\Length(
            min: 6,
            max: 4096,
            minMessage: 'Your password should be at least {{ limit }} characters.',
          ),
        ],
        'mapped' => false,
        'required' => false,
      ])

      # also confirm the password and asset that the two passwords match
      ->add('confirmPassword', FormTypes\PasswordType::class, [
        'label' => 'Confirm Password',
        'mapped' => false,
        'required' => false,
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

      // Both are mapped => false, so the entity will never see them.
      // Cast to string to avoid nulls, which would make the comparison fail.
      $plainPassword = (string)$form->get('plainPassword')->getData();
      $confirmPassword = (string)$form->get('confirmPassword')->getData();

      // Both blank is the normal case on an edit form — the admin is changing
      // the email or roles and leaving the password alone. Not an error.
      if ($plainPassword === '' && $confirmPassword === '') {
        return;
      }

      // One blank means a half-finished change. Say so rather than silently
      // guessing at which field they meant.
      if ($plainPassword === '' || $confirmPassword === '') {
        $form->get('confirmPassword')->addError(
          new FormError('Enter the new password in both fields to change it.')
        );
        return;
      }

      if ($plainPassword !== $confirmPassword) {
        $form->get('confirmPassword')->addError(
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
