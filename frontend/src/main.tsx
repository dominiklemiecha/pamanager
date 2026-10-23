import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import App from './App';

// Niente StrictMode: in sviluppo rimonterebbe le pagine e rifarebbe le
// richieste dopo che gli script del PHP hanno gia' preso in carico il markup.
// Cosi' lo sviluppo si comporta come la produzione.
createRoot(document.getElementById('root')!).render(
  <BrowserRouter>
    <App />
  </BrowserRouter>,
);
