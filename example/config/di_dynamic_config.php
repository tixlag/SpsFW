<?php

use SpsNext\Exchange1C\Config\Exchange1CConfigBuilder;

require_once __DIR__ . '/cache_helpers.php';

// Build and cache Exchange1CConfig
// Затем создаем клиента из фабрики
//        #[Inject]
//        private Exchange1CClientFactory $clientFactory
cacheConfig('exchange1c_config', function() {
    return new Exchange1CConfigBuilder()
        ->setStateFile(__DIR__ . '/../.tmp/state/1c_down.state')
//        Временно, пока тестируют 1С
        ->addBearerEndpoint('employees_uat', 'http://193.8.209.170:9088/UAT/hs/api/userinfo', $_ENV['TOKEN_1C'] ?? '', 900)
        ->addTokenEndpoint('employees_erp', 'http://193.8.209.170:9088/ERP/hs/api/userinfo', $_ENV['TOKEN_1C'] ?? '')
        ->addBasicAuthEndpoint('siz', 'https://api-erp.sps38.pro/ERP/hs/api/equipmentinoperation', $_ENV['ERP_USERNAME'], $_ENV['ERP_PASSWORD'])
        ->addNoAuthEndpoint('salary', 'http://193.8.209.170:9088/UAT/hs/api/salaryNew', 900)
        ->addBasicAuthEndpoint('training-center', 'https://api-erp.sps38.pro/ERP/hs/api/v1', $_ENV['ERP_USERNAME'], $_ENV['ERP_PASSWORD'])
        ->addBasicAuthEndpoint('training-center', 'https://api-erp.sps38.pro/ERP/hs/api/v1', $_ENV['ERP_USERNAME'], $_ENV['ERP_PASSWORD'])
        ->addTokenEndpoint('productivity', 'http://193.8.209.170:9088/UAT/hs/api/productivityV2', $_ENV['ERP_HEADER_TOKEN'] ?? '', 900)
        ->addBasicAuthEndpoint('productivity-erp', 'https://api-erp.sps38.pro/ERP/hs/api/TimeWorkedReports', $_ENV['ERP_USERNAME'], $_ENV['ERP_PASSWORD'])
        ->addBasicAuthEndpoint('1c-endpoint-download-from-SDOS', 'https://api-erp.sps38.pro/ERP/hs/api/photo', $_ENV['ERP_USERNAME'], $_ENV['ERP_PASSWORD'])
        ->addBasicAuthEndpoint('tickets', 'https://api-erp.sps38.pro/ERP/hs/api/tickets/ticketsGET', $_ENV['ERP_USERNAME'], $_ENV['ERP_PASSWORD'])
        ->addBasicAuthEndpoint('tickets-wrong', 'https://api-erp.sps38.pro/ERP/hs/api/wrongTicket', $_ENV['ERP_USERNAME'], $_ENV['ERP_PASSWORD'])
        ->addBasicAuthEndpoint('tickets-file', 'https://api-erp.sps38.pro/ERP/hs/api/ticketFile', $_ENV['ERP_USERNAME'], $_ENV['ERP_PASSWORD'])
        ->addBasicAuthEndpoint('docs-deadlines', 'https://api-erp.sps38.pro/ERP/hs/api/docsdeadline', $_ENV['ERP_USERNAME'], $_ENV['ERP_PASSWORD'])
        ->addBasicAuthEndpoint('vehicle-tasks-import', 'https://api-erp.sps38.pro/ERP/hs/api/tasksts', $_ENV['ERP_USERNAME'], $_ENV['ERP_PASSWORD'])
        ->build();

});