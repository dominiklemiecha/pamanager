import { Route, Routes } from 'react-router-dom';
import Stato from './pages/Stato';

export default function App() {
  return (
    <Routes>
      <Route path="*" element={<Stato />} />
    </Routes>
  );
}
