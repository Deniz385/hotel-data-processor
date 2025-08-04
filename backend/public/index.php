<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\HotelProcessor;

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

header('Content-Type: application/json');

switch ($requestUri) {
    case '/upload':
        if ($method === 'POST') {
            $response = []; 

            if (isset($_FILES['csvFile']) && $_FILES['csvFile']['error'] === UPLOAD_ERR_OK) {
                
                $uploadDir = __DIR__ . '/../uploads';
                if (!is_dir($uploadDir)) {
                    if (!mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
                         http_response_code(500);
                         echo json_encode(['error' => "Sunucuda 'uploads' klasörü oluşturulamadı. Lütfen manuel olarak oluşturun veya izinleri kontrol edin."]);
                         exit;
                    }
                }

                $tempFilePath = $_FILES['csvFile']['tmp_name'];
                $safeFilePath = $uploadDir . '/' . uniqid('hotel-') . '-' . basename($_FILES['csvFile']['name']);

                if (move_uploaded_file($tempFilePath, $safeFilePath)) {
                    $processor = new HotelProcessor($safeFilePath);
                    $processResult = $processor->processCsv();

                    unlink($safeFilePath); 

                    if ($processResult['success']) {
                        $outputDir = __DIR__ . '/../output';
                        if (!is_dir($outputDir)) {
                            mkdir($outputDir, 0777, true);
                        }
                        $sqliteDbPath = $outputDir . '/hotels.sqlite';

                        $jsonSaveResult = $processor->saveToJson($outputDir);
                        $sqliteSaveResult = $processor->saveToSqlite($sqliteDbPath);

                        $response = [
                            'message' => 'CSV dosyası başarıyla işlendi ve kaydedildi!',
                            'valid_count' => $processResult['valid_count'],
                            'invalid_count' => $processResult['invalid_count'],
                            'processor_errors' => $processResult['errors'],
                            'save_results' => [
                                'json' => $jsonSaveResult,
                                'sqlite' => $sqliteSaveResult,
                            ],
                        ];
                        http_response_code(200);

                    } else {
                        $response = [
                            'error' => $processResult['message'],
                            'details' => $processResult['errors'],
                        ];
                        http_response_code(400); 
                    }
                } else {
                    $move_error = error_get_last();
                    $response = [
                        'error' => 'Yüklenen dosya sunucuda işlenemedi.',
                        'debug_info' => 'move_uploaded_file fonksiyonu başarısız oldu. Olası sebep: backend/uploads klasöründe yazma izni sorunu.',
                        'php_error_details' => $move_error
                    ];
                    http_response_code(500); 
                }
            } else {
                $response = ['error' => 'Dosya yüklenemedi veya geçersiz bir dosya.'];
                if (isset($_FILES['csvFile']['error'])) {
                    $response['file_error_code'] = $_FILES['csvFile']['error'];
                }
                http_response_code(400); 
            }
            
            echo json_encode($response);

        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method Not Allowed']);
        }
        break;

     case '/data':
        if ($method === 'GET') {
            $sqliteDbPath = __DIR__ . '/../output/hotels.sqlite';
            $response = [];

            if (file_exists($sqliteDbPath)) {
                try {
                    $db = new \SQLite3($sqliteDbPath, SQLITE3_OPEN_READONLY);
                    $results = $db->query('SELECT name, address, stars, uri, contact, phone FROM hotels ORDER BY name ASC');
                    
                    $hotels = [];
                    while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
                        $hotels[] = $row;
                    }
                    
                    $db->close();
                    $response = ['success' => true, 'data' => $hotels];
                    http_response_code(200);

                } catch (\Exception $e) {
                    http_response_code(500);
                    $response = ['success' => false, 'error' => 'Veritabanı hatası: ' . $e->getMessage()];
                }
            } else {
                $response = ['success' => true, 'data' => []];
                http_response_code(200);
            }
            
            echo json_encode($response);

        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method Not Allowed']);
        }
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Not Found']);
        break;
}
?>