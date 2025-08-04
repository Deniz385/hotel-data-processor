import './globals.css'; 

export const metadata = {
  title: 'Otel Veri İşleyici',
  description: 'CSV otel verilerini işleme ve görselleştirme uygulaması',
};

export default function RootLayout({ children }) {
  return (
    <html lang="en">
      <body>{children}</body>
    </html>
  );
}