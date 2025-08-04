'use client';

import { useState, useEffect, useMemo } from 'react';

export default function HomePage() {
  const [selectedFile, setSelectedFile] = useState(null);
  const [uploadMessage, setUploadMessage] = useState('');
  const [uploadError, setUploadError] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [hotels, setHotels] = useState([]); 
  const [theme, setTheme] = useState('light');
  const [searchTerm, setSearchTerm] = useState(''); 
  const [sortBy, setSortBy] = useState('name_asc'); 

  useEffect(() => {
    const savedTheme = localStorage.getItem('theme');
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    const defaultTheme = savedTheme || (prefersDark ? 'dark' : 'light');
    setTheme(defaultTheme);
  }, []);

  useEffect(() => {
    document.body.className = theme;
    localStorage.setItem('theme', theme);
  }, [theme]);

  const toggleTheme = () => {
    setTheme(prevTheme => (prevTheme === 'light' ? 'dark' : 'light'));
  };

  const fetchHotelData = async () => {
    setIsLoading(true);
    setUploadError(''); 
    try {
      const response = await fetch('http://localhost:8000/data');
      const result = await response.json();
      if (result.success) {
        setHotels(result.data);
      } else {
        setUploadError('İşlenmiş veriler sunucudan çekilemedi.');
      }
    } catch (error) {
      console.error('Veri çekme hatası:', error);
      setUploadError('Veri çekme sırasında bir ağ hatası oluştu.');
    } finally {
      setIsLoading(false);
    }
  };
  
  const handleFileChange = (event) => {
    setSelectedFile(event.target.files[0]);
    setUploadMessage('');
    setUploadError('');
    setHotels([]);
  };

  const handleFileUpload = async () => {
    if (!selectedFile) {
      setUploadError('Lütfen önce bir CSV dosyası seçin.');
      return;
    }

    setIsLoading(true);
    setUploadMessage('Yükleniyor ve işleniyor...');
    setUploadError('');

    const formData = new FormData();
    formData.append('csvFile', selectedFile);

    try {
      const response = await fetch('http://localhost:8000/upload', {
        method: 'POST',
        body: formData,
      });

      const data = await response.json();

      if (response.ok) {
        setUploadMessage(data.message || 'Dosya başarıyla yüklendi!');
        console.log('Backend yanıtı:', data);
        await fetchHotelData(); 
      } else {
        setUploadError(data.error || 'Dosya yüklenirken bir hata oluştu.');
        console.error('Backend hata yanıtı:', data);
      }
    } catch (error) {
      console.error('API çağrısı sırasında hata:', error);
      setUploadError('Sunucuya bağlanılamadı veya bir ağ hatası oluştu.');
    } finally {
      setIsLoading(false);
      if (document.querySelector('input[type="file"]')) {
        document.querySelector('input[type="file"]').value = '';
      }
      setSelectedFile(null);
    }
  };

  const filteredAndSortedHotels = useMemo(() => {
    let result = [...hotels];

    if (searchTerm) {
      result = result.filter(hotel =>
        hotel.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
        hotel.address.toLowerCase().includes(searchTerm.toLowerCase())
      );
    }

    switch (sortBy) {
      case 'name_asc':
        result.sort((a, b) => a.name.localeCompare(b.name));
        break;
      case 'name_desc':
        result.sort((a, b) => b.name.localeCompare(a.name));
        break;
      case 'stars_desc':
        result.sort((a, b) => b.stars - a.stars);
        break;
      case 'stars_asc':
        result.sort((a, b) => a.stars - b.stars);
        break;
      default:
        break;
    }
    return result;
  }, [hotels, searchTerm, sortBy]);


  return (
    <div className="container">
      <header className="header">
        <button onClick={toggleTheme} className="theme-toggle-button" aria-label="Toggle theme">
          {theme === 'light' ? '🌙' : '☀️'}
        </button>
        <h1>Otel Veri İşleyici</h1>
        <p>CSV dosyanızı yükleyin, verileri işleyin ve görüntüleyin.</p>
      </header>

      <main className="main-content">
        <section className="upload-section">
          <input type="file" accept=".csv" onChange={handleFileChange} disabled={isLoading} />
          <button onClick={handleFileUpload} disabled={!selectedFile || isLoading}>
            {isLoading ? 'İşleniyor...' : 'CSV Yükle ve Görüntüle'}
          </button>

          {uploadMessage && <p style={{ color: 'green' }}>{uploadMessage}</p>}
          {uploadError && <p style={{ color: 'red' }}>{uploadError}</p>}
        </section>
        
        <section className="visualization-section">
          <h2>Doğrulanmış Otel Verileri</h2>

          {hotels.length > 0 && (
            <div className="controls-container">
              <div className="search-box">
                <input
                  type="text"
                  placeholder="Otel adı veya adreste ara..."
                  value={searchTerm}
                  onChange={e => setSearchTerm(e.target.value)}
                />
              </div>
              <div className="sort-box">
                <select value={sortBy} onChange={e => setSortBy(e.target.value)}>
                  <option value="name_asc">İsme Göre Sırala (A-Z)</option>
                  <option value="name_desc">İsme Göre Sırala (Z-A)</option>
                  <option value="stars_desc">Yıldıza Göre (En Yüksek)</option>
                  <option value="stars_asc">Yıldıza Göre (En Düşük)</option>
                </select>
              </div>
            </div>
          )}
          
          {isLoading && hotels.length === 0 && <p>Veriler yükleniyor...</p>}
          
          {!isLoading && hotels.length > 0 ? (
            <table className="hotel-table">
  <thead>
    <tr>
      <th>Otel Adı</th>
      <th>Adres</th>
      <th>Yıldız</th>
      <th>İletişim Kişisi</th> 
      <th>Telefon</th>         
      <th>URL</th>
    </tr>
  </thead>
  <tbody>
    {filteredAndSortedHotels.length > 0 ? (
      filteredAndSortedHotels.map((hotel, index) => (
        <tr key={index}>
          <td>{hotel.name}</td>
          <td>{hotel.address}</td>
          <td>{hotel.stars}</td>
          <td>{hotel.contact}</td> 
          <td>{hotel.phone}</td>  
          <td><a href={hotel.uri} target="_blank" rel="noopener noreferrer">Ziyaret Et</a></td>
        </tr>
      ))
    ) : (
      <tr>
        <td colSpan="6" style={{ textAlign: 'center', padding: '2rem' }}>Arama kriterlerine uygun otel bulunamadı.</td>
      </tr>
    )}
  </tbody>
</table>
          ) : (
            !isLoading && !uploadMessage && <p>Başlamak için bir CSV dosyası yükleyin.</p>
          )}

        </section>
      </main>
    </div>
  );
}