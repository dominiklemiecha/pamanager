import { Injectable } from '@nestjs/common';
import { createHmac, randomBytes } from 'crypto';

const pulisci = (v?: string) => String(v ?? '').trim().replace(/^["']+|["']+$/g, '').trim();
const b64 = (s: string | Buffer) => Buffer.from(s).toString('base64').replace(/=+$/, '').replace(/\+/g, '-').replace(/\//g, '_');

/**
 * Widget assistenza del CRM Connecteed (ConnecteedWidget.php): tag dello
 * script per le pagine di chi e' collegato e dichiarazione firmata
 * dell'utente (JWT HS256, 5 minuti). La chiave resta sul server.
 * ENV: CONNECTEED_APP_ID, CONNECTEED_APP_SECRET, CONNECTEED_WIDGET_ORIGIN.
 */
@Injectable()
export class WidgetService {
  configurato(): boolean {
    return pulisci(process.env.CONNECTEED_APP_ID) !== '' && pulisci(process.env.CONNECTEED_APP_SECRET) !== '';
  }

  /** Dati del tag script, o null se l'assistenza non e' configurata. */
  tag(): { src: string; appId: string; sessione: string } | null {
    if (!this.configurato()) return null;
    const o = pulisci(process.env.CONNECTEED_WIDGET_ORIGIN).replace(/\/+$/, '');
    const origine = /^https:\/\/[a-z0-9.-]+(:\d+)?$/i.test(o) ? o : 'https://fase.adichub.com';
    return { src: `${origine}/api/v1/widget/v1.js`, appId: pulisci(process.env.CONNECTEED_APP_ID), sessione: '/api/assistenza-sessione.php' };
  }

  firma(identita: { sub: string; name: string; role: string }, ctx: Record<string, string>): string {
    const ora = Math.floor(Date.now() / 1000);
    const testa = b64(JSON.stringify({ alg: 'HS256', typ: 'JWT' }));
    const corpo = b64(JSON.stringify({
      jti: randomBytes(16).toString('hex'), iss: pulisci(process.env.CONNECTEED_APP_ID), aud: 'connecteed-widget',
      iat: ora, exp: ora + 300, sub: identita.sub, name: identita.name, role: identita.role, ctx,
    }));
    const sig = b64(createHmac('sha256', pulisci(process.env.CONNECTEED_APP_SECRET)).update(`${testa}.${corpo}`).digest());
    return `${testa}.${corpo}.${sig}`;
  }
}
