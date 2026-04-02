# PhpIni Attribute

The `#[PhpIni]` attribute allows you to set any PHP INI settings for individual controller methods. This is useful when you have specific endpoints that require different PHP configurations during execution.

## Usage

Apply the attribute to any controller method:

```php
use SpsFW\Core\Attributes\PhpIni;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Http\Response;

class YourController
{
    #[Route(path: '/process-large-file', httpMethods: ['POST'])]
    #[PhpIni(settings: ['memory_limit' => '512M', 'max_execution_time' => '300'])]
    public function processLargeFile(): Response
    {
        // This method will have memory limit set to 512M and max execution time to 300 seconds during execution
        // INI settings are automatically restored after method execution
        
        // Your memory-intensive code here
        return Response::json(['status' => 'processed']);
    }
    
    #[Route(path: '/change-upload-settings', httpMethods: ['POST'])]
    #[PhpIni(settings: ['upload_max_filesize' => '50M', 'post_max_size' => '50M'])]
    public function handleLargeUpload(): Response
    {
        // This method will have upload settings adjusted during execution
        
        return Response::json(['status' => 'upload settings adjusted']);
    }
    
    #[Route(path: '/default-operation', httpMethods: ['GET'])]
    public function defaultOperation(): Response
    {
        // This method uses the default PHP INI settings (no attribute applied)
        
        return Response::json(['status' => 'complete']);
    }
}
```

## Features

- Multiple INI settings can be applied at once
- INI settings are set before the method executes and automatically restored afterward
- Safe to use - the original INI values are always restored even if an exception occurs
- Can be applied to any controller method that handles HTTP requests
- Supports all valid PHP INI settings that can be changed with ini_set()

## Important Notes

- The INI settings are only applied during the execution of the annotated method
- After the method completes (either normally or due to an exception), the original INI values are restored
- Be cautious when setting INI values as this could impact server performance or security
- The attribute only affects the specific controller method it's applied to
- Some INI settings may not be changeable depending on PHP's configuration (safe_mode, etc.)