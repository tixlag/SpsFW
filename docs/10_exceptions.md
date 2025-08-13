# Исключения

Кидаем `BaseException` или наследуемся от него, при создании новых видов исключений

## Примеры

```php
<?php
namespace App\Controllers;

use SpsFW\Core\Exceptions\BaseException;

class SecureController  extends RestController
{
    
    #[Route('/api/admin/data', ['GET'])]
    #[AccessRulesAny([AdminRules::VIEW_DATA])]
    public function example(): Response
    {
        throw new BaseException('Исключительная ситуация', code: 500)
    }

}
?>
```
