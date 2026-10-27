/** Dati della cornice dello staff (GET /api/v1/staff/cornice). */
export interface CorniceStaff {
  ruolo: string;
  area: string;
  nome: string;
  iniziali: string;
  foto: string;
  contatori: { ferie: number; chat: number; inviti: number; assunzioni: number; attestati: number; prove: number };
  sottotitoli: { emp: string; comm: string; pres: string; dept: string };
  aziende: Array<{ id: number; name: string; is_active: boolean; attivita: number }>;
  altrove: number;
  puoUscire: boolean;
  azienda: { id: number; name: string; is_active: boolean } | null;
  aziendaIniziali: string;
  widget: { src: string; appId: string; sessione: string } | null;
}
