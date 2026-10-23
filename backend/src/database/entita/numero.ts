import { ValueTransformer } from 'typeorm';

/**
 * Le colonne numeric arrivano da Postgres come stringhe: qui diventano numeri.
 * Gli importi hanno al massimo 2 decimali, quindi il passaggio e' esatto.
 */
export const numero: ValueTransformer = {
  to: (v: number | null | undefined) => v,
  from: (v: string | null) => (v === null || v === undefined ? null : Number(v)),
};
