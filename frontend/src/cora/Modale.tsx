import { ReactNode, useEffect, useState } from 'react';
import { createPortal } from 'react-dom';

/**
 * Modale di Cora (.cora-modal) come in cora.js: esce dal contenitore della
 * pagina (va nel body), si apre con una transizione, si chiude con la X, il
 * clic sullo sfondo o Esc.
 */
export function Modale({ id, aperta, onChiudi, children }: { id: string; aperta: boolean; onChiudi: () => void; children: ReactNode }) {
  const [visibile, setVisibile] = useState(aperta);
  const [classe, setClasse] = useState(false);
  useEffect(() => {
    if (aperta) {
      setVisibile(true);
      const r = requestAnimationFrame(() => setClasse(true));
      return () => cancelAnimationFrame(r);
    }
    setClasse(false);
    const t = setTimeout(() => setVisibile(false), 180);
    return () => clearTimeout(t);
  }, [aperta]);
  useEffect(() => {
    if (!aperta) return;
    const esc = (e: KeyboardEvent) => { if (e.key === 'Escape') onChiudi(); };
    document.addEventListener('keydown', esc);
    return () => document.removeEventListener('keydown', esc);
  }, [aperta, onChiudi]);
  return createPortal(
    <div className={'cora-modal' + (classe ? ' is-open' : '')} id={id} hidden={!visibile}
      onClick={(e) => { if (e.target === e.currentTarget) onChiudi(); }}>
      {children}
    </div>,
    document.body,
  );
}
