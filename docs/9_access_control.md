# Управление доступом (Access Control)

Управление доступом к маршрутам осуществляется через атрибуты авторизации.

## Атрибуты авторизации

### `#[NoAuthAccess]`

Разрешает доступ без аутентификации.

### `#[AccessRulesAny(array $rules)]`

Требует наличия *хотя бы одного* из указанных правил доступа.

### `#[AccessRulesAll(array $rules)]`

Требует наличия *всех* указанных правил доступа.


## Получение значения правила доступа

Если у правила доступа есть некоторое значение, то его можно получить с помощью 
```php 
AccessChecker::getValue($ruleId);
```

## Примеры

```php
<?php
namespace App\Controllers;

use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Attributes\NoAuthAccess;
use SpsFW\Core\Attributes\AccessRulesAny;
use SpsFW\Core\Attributes\AccessRulesAll;
use SpsFW\Core\Http\Response;
use App\AccessRules\AdminRules;
use App\AccessRules\UserRules;
use App\Secure\SecureService;

class SecureController  extends RestController
{
    
    public function __construct() {
    #[Inject]
    private SecureService $secureService;
    }


    #[Route('/api/admin/data', ['GET'])]
    #[AccessRulesAny([AdminRules::VIEW_DATA])]
    public function getAdminData(): Response
    {
        $idsOfSections = AccessChecker::getValue(AdminRules::VIEW_DATA);
        return Response::json($this->secureService->getData($idsOfSections));
    }

    #[Route('/api/user/profile', ['GET'])]
    #[AccessRulesAll([UserRules::LOGGED_IN, UserRules::PROFILE_ACCESS])]
    public function getUserProfile(): Response
    {
        // Доступен, если у пользователя есть И правило UserRules::LOGGED_IN И правило UserRules::PROFILE_ACCESS
    }

    #[Route('/api/public/info', ['GET'])]
    #[NoAuthAccess]
    public function getPublicInfo(): Response
    {
        // Доступен всем, в том числе неаутентифицированным пользователям
    }
}
?>
```
