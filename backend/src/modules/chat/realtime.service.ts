import { Injectable, Logger } from '@nestjs/common';
import { createHmac } from 'crypto';

const b64 = (s: string | Buffer) => Buffer.from(s).toString('base64').replace(/=+$/, '').replace(/\+/g, '-').replace(/\//g, '_');

/**
 * Tempo reale della chat (Realtime.php): token per collegarsi al servizio
 * Socket.IO (cartella realtime/, invariato) e consegna degli eventi dopo il
 * salvataggio. Se il servizio non risponde il dato resta nel database e la
 * pagina lo ritrova con il polling di riserva.
 * ENV: REALTIME_SECRET, REALTIME_URL (default http://realtime:3001), REALTIME_ENABLED.
 */
@Injectable()
export class RealtimeService {
  private readonly logger = new Logger('Realtime');
  static readonly TOKEN_TTL = 3600;

  attivo(): boolean {
    if (!process.env.REALTIME_SECRET) return false;
    return !['off', 'false', '0', 'no'].includes(String(process.env.REALTIME_ENABLED ?? 'true').toLowerCase());
  }

  /** Token HS256 valido un'ora: { sub: "employee:12", cid, exp }. */
  token(tipo: string, id: number, companyId: number): string | null {
    if (!this.attivo()) return null;
    const testa = b64(JSON.stringify({ alg: 'HS256', typ: 'JWT' }));
    const corpo = b64(JSON.stringify({ sub: `${tipo}:${id}`, cid: companyId, exp: Math.floor(Date.now() / 1000) + RealtimeService.TOKEN_TTL }));
    const firma = b64(createHmac('sha256', process.env.REALTIME_SECRET!).update(`${testa}.${corpo}`).digest());
    return `${testa}.${corpo}.${firma}`;
  }

  /** Evento a piu' persone, contenuto diverso per ciascuna (sub => data). */
  async emitEach(companyId: number, tipo: string, perPersona: Record<string, unknown>): Promise<void> {
    const eventi = Object.entries(perPersona).map(([sub, data]) => ({ sub, data }));
    if (!eventi.length) return;
    await this.chiama('/emit', { cid: companyId, type: tipo, eventi });
  }

  async emit(companyId: number, tipo: string, subs: string[], data: unknown): Promise<void> {
    const per: Record<string, unknown> = {};
    for (const s of new Set(subs)) per[s] = data;
    await this.emitEach(companyId, tipo, per);
  }

  async emitAzienda(companyId: number, tipo: string, data: unknown): Promise<void> {
    await this.chiama('/emit', { cid: companyId, type: tipo, azienda: true, data });
  }

  /** Chi ha la chat aperta in primo piano: a loro il push non serve. */
  async guardano(companyId: number, conversazione: number, subs: string[]): Promise<Record<string, { guarda?: boolean; attivo?: boolean }>> {
    const r = await this.chiama('/stato', { cid: companyId, conv: conversazione, subs });
    return r && typeof r.stato === 'object' && r.stato ? r.stato : {};
  }

  private async chiama(percorso: string, corpo: unknown): Promise<any | null> {
    if (!this.attivo()) return null;
    try {
      const r = await fetch((process.env.REALTIME_URL || 'http://realtime:3001').replace(/\/+$/, '') + percorso, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Realtime-Secret': process.env.REALTIME_SECRET! },
        body: JSON.stringify(corpo),
        signal: AbortSignal.timeout(600),
      });
      if (r.status !== 200) return null;
      return await r.json().catch(() => null);
    } catch (e: any) {
      this.logger.warn(`servizio non raggiungibile: ${e?.message ?? e}`);
      return null;
    }
  }
}
