<?php
namespace App;

class HotelProcessor
{
    private $csvFilePath;
    private $validHotels = [];
    private $invalidHotels = [];
    private $errors = [];

    public function __construct(string $csvFilePath)
    {
        $this->csvFilePath = $csvFilePath;
    }

      public function processCsv(): array
    {
        ini_set('auto_detect_line_endings', TRUE);

        if (!file_exists($this->csvFilePath)) {
            $this->errors[] = "CSV dosyası bulunamadı: " . $this->csvFilePath;
            return ['success' => false, 'message' => 'CSV dosyası bulunamadı.', 'errors' => $this->errors];
        }

        if (($handle = fopen($this->csvFilePath, 'r')) === false) {
            $this->errors[] = "CSV dosyası açılamadı: " . $this->csvFilePath;
            return ['success' => false, 'message' => 'CSV dosyası açılamadı.', 'errors' => $this->errors];
        }

        $header = fgetcsv($handle);

        if ($header === false || empty($header)) {
            $this->errors[] = "CSV dosyası başlık satırı okunamadı veya boş.";
            fclose($handle);
            return ['success' => false, 'message' => 'CSV başlığı okunamadı veya boş.', 'errors' => $this->errors];
        }

        $header[0] = preg_replace('/^\x{FEFF}/u', '', $header[0]);
        $header = array_map('trim', $header);

        $requiredHeaders = ['name', 'uri', 'stars'];
        foreach ($requiredHeaders as $reqHeader) {
            if (!in_array($reqHeader, $header)) {
                $this->errors[] = "CSV dosyası '$reqHeader' başlık sütunu eksik.";
                fclose($handle);
                return ['success' => false, 'message' => "Gerekli CSV başlığı eksik: '$reqHeader'.", 'errors' => $this->errors];
            }
        }
        
        $lineNum = 1;

        while (($data = fgetcsv($handle)) !== false) {
            $lineNum++;
            if (count($header) !== count($data)) {
                $this->invalidHotels[] = [
                    'data' => array_pad($data, count($header), null), 
                    'line' => $lineNum, 
                    'errors' => ["Sütun sayısı hatası. Beklenen: " . count($header) . ", Gelen: " . count($data)]
                ];
                continue;
            }

            $hotelData = array_combine($header, $data);
            $validationErrors = $this->validateHotelData($hotelData, $lineNum);

            if (empty($validationErrors)) {
                $this->validHotels[] = $hotelData;
            } else {
                $this->invalidHotels[] = ['data' => $hotelData, 'line' => $lineNum, 'errors' => $validationErrors];
            }
        }

        fclose($handle);

        ini_set('auto_detect_line_endings', FALSE);

        return [
            'success' => true,
            'message' => 'CSV işleme tamamlandı.',
            'valid_count' => count($this->validHotels),
            'invalid_count' => count($this->invalidHotels),
            'errors' => $this->errors,
        ];
    }

    private function validateHotelData(array $hotelData, int $lineNum): array
    {
        $errors = [];

        if (!isset($hotelData['name']) || trim($hotelData['name']) === '') {
            $errors[] = "Satır {$lineNum}: Otel adı eksik veya boş.";
        } elseif (!mb_check_encoding($hotelData['name'], 'UTF-8')) {
            $errors[] = "Satır {$lineNum}: Otel adı geçersiz UTF-8 karakterler içeriyor.";
        }

        if (!isset($hotelData['uri']) || trim($hotelData['uri']) === '') { 
            $errors[] = "Satır {$lineNum}: Otel URL'si eksik veya boş.";
        } elseif (!filter_var($hotelData['uri'], FILTER_VALIDATE_URL)) { 
            $errors[] = "Satır {$lineNum}: Otel URL'si geçersiz formatta.";
        }

        if (!isset($hotelData['stars']) || trim($hotelData['stars']) === '') { 
            $errors[] = "Satır {$lineNum}: Otel derecelendirmesi eksik veya boş.";
        } elseif (!is_numeric($hotelData['stars']) || (float)$hotelData['stars'] < 0 || (float)$hotelData['stars'] > 5) { 
            $errors[] = "Satır {$lineNum}: Otel derecelendirmesi 0-5 arasında geçerli bir sayı değil.";
        }

        return $errors;
    }

    public function getValidHotels(): array
    {
        return $this->validHotels;
    }

    public function getInvalidHotels(): array
    {
        return $this->invalidHotels;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function saveToJson(string $outputDir): array
    {
        $outputFilePath = rtrim($outputDir, '/') . '/hotels_valid.json';
        

        $jsonData = json_encode($this->validHotels, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($jsonData === false) {
            return ['success' => false, 'message' => 'JSON verisi oluşturulamadı: ' . json_last_error_msg()];
        }

        if (file_put_contents($outputFilePath, $jsonData) === false) {
            return ['success' => false, 'message' => 'JSON dosyası yazılamadı: ' . $outputFilePath];
        }

        return ['success' => true, 'message' => 'JSON dosyası başarıyla kaydedildi.', 'path' => $outputFilePath];
    }

   public function saveToSqlite(string $outputDbPath): array
    {
        try {
            $db = new \SQLite3($outputDbPath);
            $db->exec('PRAGMA foreign_keys = ON;');

            $createTableSQL = "CREATE TABLE IF NOT EXISTS hotels (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                uri TEXT NOT NULL,
                stars REAL NOT NULL,
                address TEXT NULL,
                contact TEXT NULL, 
                phone TEXT NULL   
            )";
            $db->exec($createTableSQL);
            $db->exec("DELETE FROM hotels");

            $insertSQL = "INSERT INTO hotels (name, uri, stars, address, contact, phone) 
                          VALUES (:name, :uri, :stars, :address, :contact, :phone)";
            $stmt = $db->prepare($insertSQL);

            if (!$stmt) {
                return ['success' => false, 'message' => 'SQLite Prepared Statement oluşturulamadı: ' . $db->lastErrorMsg()];
            }

            foreach ($this->validHotels as $hotel) {
                $stmt->bindValue(':name', $hotel['name'] ?? null, SQLITE3_TEXT);
                $stmt->bindValue(':uri', $hotel['uri'] ?? null, SQLITE3_TEXT);
                $stmt->bindValue(':stars', isset($hotel['stars']) ? (float)$hotel['stars'] : null, SQLITE3_FLOAT);
                $stmt->bindValue(':address', $hotel['address'] ?? null, SQLITE3_TEXT);
                $stmt->bindValue(':contact', $hotel['contact'] ?? null, SQLITE3_TEXT); 
                $stmt->bindValue(':phone', $hotel['phone'] ?? null, SQLITE3_TEXT);     

                $executeResult = $stmt->execute();
                if (!$executeResult) {
                    throw new \Exception("Kayıt eklenirken hata oluştu: " . $db->lastErrorMsg());
                }
                $executeResult->finalize();
            }

            $db->close();
            return ['success' => true, 'message' => 'SQLite veritabanına başarıyla kaydedildi.', 'path' => $outputDbPath];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'SQLite\'a kaydedilirken hata: ' . $e->getMessage()];
        }
    }

    }
