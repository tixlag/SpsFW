<?php
    
namespace SpsFW\Core\Router;

class RoutesCache {
    public static $routes =  array (
  'GET:/api/v3/employees/recommendations/excel' => 
  array (
    'controller' => 'SpsFW\\Api\\Additional\\EmployeesRecommendations\\EmployeesRecommendationsController',
    'httpMethod' => 'GET',
    'method' => 'getExcel',
    'rawPath' => '/api/v3/employees/recommendations/excel',
    'pattern' => '#^/api/v3/employees/recommendations/excel$#',
    'params' => 
    array (
    ),
    'middlewares' => 
    array (
    ),
    'access_rules' => 
    array (
      0 => 
      \Sps\UserAccess\AccessRulesEnum::Sections_Talents,
    ),
  ),
  'POST:/api/v3/employees/recommendations' => 
  array (
    'controller' => 'SpsFW\\Api\\Additional\\EmployeesRecommendations\\EmployeesRecommendationsController',
    'httpMethod' => 'POST',
    'method' => 'addRecommendation',
    'rawPath' => '/api/v3/employees/recommendations',
    'pattern' => '#^/api/v3/employees/recommendations$#',
    'params' => 
    array (
    ),
    'middlewares' => 
    array (
    ),
    'access_rules' => 
    array (
    ),
  ),
  'GET:/api/v3/employees/{recommender_code_1c}/recommendations' => 
  array (
    'controller' => 'SpsFW\\Api\\Additional\\EmployeesRecommendations\\EmployeesRecommendationsController',
    'httpMethod' => 'GET',
    'method' => 'getRecommendation',
    'rawPath' => '/api/v3/employees/{recommender_code_1c}/recommendations',
    'pattern' => '#^/api/v3/employees/([^/]+)/recommendations$#',
    'params' => 
    array (
      0 => 'recommender_code_1c',
    ),
    'middlewares' => 
    array (
    ),
    'access_rules' => 
    array (
    ),
  ),
  'POST:/api/v3/corporate-slider/categories' => 
  array (
    'controller' => 'SpsFW\\Api\\CorporateSlider\\Controllers\\CategoryController',
    'httpMethod' => 'POST',
    'method' => 'createCategory',
    'rawPath' => '/api/v3/corporate-slider/categories',
    'pattern' => '#^/api/v3/corporate-slider/categories$#',
    'params' => 
    array (
    ),
    'middlewares' => 
    array (
    ),
    'access_rules' => 
    array (
    ),
  ),
  'POST:/api/v3/corporate-slider/categories/{category_uuid}' => 
  array (
    'controller' => 'SpsFW\\Api\\CorporateSlider\\Controllers\\CategoryController',
    'httpMethod' => 'POST',
    'method' => 'updateCategory',
    'rawPath' => '/api/v3/corporate-slider/categories/{category_uuid}',
    'pattern' => '#^/api/v3/corporate-slider/categories/([^/]+)$#',
    'params' => 
    array (
      0 => 'category_uuid',
    ),
    'middlewares' => 
    array (
    ),
    'access_rules' => 
    array (
    ),
  ),
  'GET:/api/v3/corporate-slider/categories/{category_uuid}' => 
  array (
    'controller' => 'SpsFW\\Api\\CorporateSlider\\Controllers\\CategoryController',
    'httpMethod' => 'GET',
    'method' => 'getCategoryByUuidWithSlides',
    'rawPath' => '/api/v3/corporate-slider/categories/{category_uuid}',
    'pattern' => '#^/api/v3/corporate-slider/categories/([^/]+)$#',
    'params' => 
    array (
      0 => 'category_uuid',
    ),
    'middlewares' => 
    array (
    ),
    'access_rules' => 
    array (
    ),
  ),
  'GET:/api/v3/corporate-slider/categories' => 
  array (
    'controller' => 'SpsFW\\Api\\CorporateSlider\\Controllers\\CategoryController',
    'httpMethod' => 'GET',
    'method' => 'getAllCategoriesWithSlides',
    'rawPath' => '/api/v3/corporate-slider/categories',
    'pattern' => '#^/api/v3/corporate-slider/categories$#',
    'params' => 
    array (
    ),
    'middlewares' => 
    array (
    ),
    'access_rules' => 
    array (
    ),
  ),
  'DELETE:/api/v3/corporate-slider/categories/{category_uuid}' => 
  array (
    'controller' => 'SpsFW\\Api\\CorporateSlider\\Controllers\\CategoryController',
    'httpMethod' => 'DELETE',
    'method' => 'deleteCategory',
    'rawPath' => '/api/v3/corporate-slider/categories/{category_uuid}',
    'pattern' => '#^/api/v3/corporate-slider/categories/([^/]+)$#',
    'params' => 
    array (
      0 => 'category_uuid',
    ),
    'middlewares' => 
    array (
    ),
    'access_rules' => 
    array (
    ),
  ),
  'DELETE:/api/v3/corporate-slider/categories' => 
  array (
    'controller' => 'SpsFW\\Api\\CorporateSlider\\Controllers\\CategoryController',
    'httpMethod' => 'DELETE',
    'method' => 'deleteManyCategories',
    'rawPath' => '/api/v3/corporate-slider/categories',
    'pattern' => '#^/api/v3/corporate-slider/categories$#',
    'params' => 
    array (
    ),
    'middlewares' => 
    array (
    ),
    'access_rules' => 
    array (
    ),
  ),
  'GET:/api/v3/metrics' => 
  array (
    'controller' => 'SpsFW\\Api\\Metrics\\MetricsController',
    'httpMethod' => 'GET',
    'method' => 'index',
    'rawPath' => '/api/v3/metrics',
    'pattern' => '#^/api/v3/metrics$#',
    'params' => 
    array (
    ),
    'middlewares' => 
    array (
    ),
    'access_rules' => 
    array (
    ),
  ),
  'POST:/api/v3/secrets/drop-dining-room' => 
  array (
    'controller' => 'SpsFW\\Api\\Secrets\\SecretController',
    'httpMethod' => 'POST',
    'method' => 'dropDiningRoom',
    'rawPath' => '/api/v3/secrets/drop-dining-room',
    'pattern' => '#^/api/v3/secrets/drop-dining-room$#',
    'params' => 
    array (
    ),
    'middlewares' => 
    array (
    ),
    'access_rules' => 
    array (
    ),
  ),
  'GET:/swagger' => 
  array (
    'controller' => 'SpsFW\\Api\\Swagger\\SwaggerController',
    'httpMethod' => 'GET',
    'method' => 'index',
    'rawPath' => '/swagger',
    'pattern' => '#^/swagger$#',
    'params' => 
    array (
    ),
    'middlewares' => 
    array (
    ),
    'access_rules' => 
    array (
    ),
  ),
  'GET:/api/v3/docs/openapi.yaml' => 
  array (
    'controller' => 'SpsFW\\Api\\Swagger\\SwaggerController',
    'httpMethod' => 'GET',
    'method' => 'yaml',
    'rawPath' => '/api/v3/docs/openapi.yaml',
    'pattern' => '#^/api/v3/docs/openapi.yaml$#',
    'params' => 
    array (
    ),
    'middlewares' => 
    array (
    ),
    'access_rules' => 
    array (
    ),
  ),
  'GET:/api/v3/test/{id}/{category}' => 
  array (
    'controller' => 'SpsFW\\Api\\TestController',
    'httpMethod' => 'GET',
    'method' => 'test',
    'rawPath' => '/api/v3/test/{id}/{category}',
    'pattern' => '#^/api/v3/test/([^/]+)/([^/]+)$#',
    'params' => 
    array (
      0 => 'id',
      1 => 'category',
    ),
    'middlewares' => 
    array (
      0 => 
      array (
        'class' => 'SpsFW\\Core\\Middleware\\PerformanceMiddleware',
        'params' => 
        array (
        ),
      ),
    ),
    'access_rules' => 
    array (
    ),
  ),
  'PATCH:/api/v3/login/password/change' => 
  array (
    'controller' => 'SpsFW\\Api\\Users\\LoginController',
    'httpMethod' => 'PATCH',
    'method' => 'changePassword',
    'rawPath' => '/api/v3/login/password/change',
    'pattern' => '#^/api/v3/login/password/change$#',
    'params' => 
    array (
    ),
    'middlewares' => 
    array (
    ),
    'access_rules' => 
    array (
    ),
  ),
  'GET:/api/v3/worksheets/master/characteristics' => 
  array (
    'controller' => 'SpsFW\\Api\\WorkSheets\\Masters\\MastersController',
    'httpMethod' => 'GET',
    'method' => 'getAllMasterCharacteristic',
    'rawPath' => '/api/v3/worksheets/master/characteristics',
    'pattern' => '#^/api/v3/worksheets/master/characteristics$#',
    'params' => 
    array (
    ),
    'middlewares' => 
    array (
    ),
    'access_rules' => 
    array (
    ),
  ),
  'GET:/api/v3/worksheets/master/characteristics/{master_id}' => 
  array (
    'controller' => 'SpsFW\\Api\\WorkSheets\\Masters\\MastersController',
    'httpMethod' => 'GET',
    'method' => 'getOneMasterCharacteristic',
    'rawPath' => '/api/v3/worksheets/master/characteristics/{master_id}',
    'pattern' => '#^/api/v3/worksheets/master/characteristics/([^/]+)$#',
    'params' => 
    array (
      0 => 'master_id',
    ),
    'middlewares' => 
    array (
    ),
    'access_rules' => 
    array (
    ),
  ),
  'POST:/api/v3/worksheets/master/like-from-pto-for-day' => 
  array (
    'controller' => 'SpsFW\\Api\\WorkSheets\\Masters\\MastersController',
    'httpMethod' => 'POST',
    'method' => 'likeFromPtoForDay',
    'rawPath' => '/api/v3/worksheets/master/like-from-pto-for-day',
    'pattern' => '#^/api/v3/worksheets/master/like-from-pto-for-day$#',
    'params' => 
    array (
    ),
    'middlewares' => 
    array (
    ),
    'access_rules' => 
    array (
    ),
  ),
  'POST:/api/v3/worksheets/master/like-from-pto-for-day/comment' => 
  array (
    'controller' => 'SpsFW\\Api\\WorkSheets\\Masters\\MastersController',
    'httpMethod' => 'POST',
    'method' => 'commentToLikeFromPtoForDay',
    'rawPath' => '/api/v3/worksheets/master/like-from-pto-for-day/comment',
    'pattern' => '#^/api/v3/worksheets/master/like-from-pto-for-day/comment$#',
    'params' => 
    array (
    ),
    'middlewares' => 
    array (
    ),
    'access_rules' => 
    array (
    ),
  ),
  'GET:/api/v3/worksheets/master/report/all-day' => 
  array (
    'controller' => 'SpsFW\\Api\\WorkSheets\\Masters\\Reports\\MasterReportsController',
    'httpMethod' => 'GET',
    'method' => 'getAllReportsByDay',
    'rawPath' => '/api/v3/worksheets/master/report/all-day',
    'pattern' => '#^/api/v3/worksheets/master/report/all-day$#',
    'params' => 
    array (
    ),
    'middlewares' => 
    array (
    ),
    'access_rules' => 
    array (
    ),
  ),
  'DELETE:/api/v3/worksheets/task/{id}' => 
  array (
    'controller' => 'SpsFW\\Api\\WorkSheets\\Tasks\\TasksController',
    'httpMethod' => 'DELETE',
    'method' => 'deleteTask',
    'rawPath' => '/api/v3/worksheets/task/{id}',
    'pattern' => '#^/api/v3/worksheets/task/([^/]+)$#',
    'params' => 
    array (
      0 => 'id',
    ),
    'middlewares' => 
    array (
    ),
    'access_rules' => 
    array (
    ),
  ),
  'GET:/api/v3/core/update' => 
  array (
    'controller' => 'SpsFW\\Core\\CoreUtilController',
    'httpMethod' => 'GET',
    'method' => 'updateRoutes',
    'rawPath' => '/api/v3/core/update',
    'pattern' => '#^/api/v3/core/update$#',
    'params' => 
    array (
    ),
    'middlewares' => 
    array (
    ),
    'access_rules' => 
    array (
    ),
  ),
  'GET:/api/v3/core/update/routes' => 
  array (
    'controller' => 'SpsFW\\Core\\CoreUtilController',
    'httpMethod' => 'GET',
    'method' => 'updateOnlyRoutes',
    'rawPath' => '/api/v3/core/update/routes',
    'pattern' => '#^/api/v3/core/update/routes$#',
    'params' => 
    array (
    ),
    'middlewares' => 
    array (
    ),
    'access_rules' => 
    array (
    ),
  ),
);
}