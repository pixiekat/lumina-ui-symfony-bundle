<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Twig\Extension;

use Pixiekat\LuminaUiBundle\Twig as LuminaTwig;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class PatientTwigExtension extends AbstractExtension {
  public function getFilters(): array {
    return [
      // If your filter generates SAFE HTML, you should add a third
      // parameter: ['is_safe' => ['html']]
      // Reference: https://twig.symfony.com/doc/3.x/advanced.html#automatic-escaping
      //new TwigFilter('filter_name', [Runtime\PatientTwigExtensionRuntime::class, 'doSomething']),
    ];
  }

  public function getFunctions(): array {
    return [
      new TwigFunction('find_patient', [LuminaTwig\Runtime\PatientTwigExtensionRuntime::class, 'findPatient']),
    ];
  }
}
