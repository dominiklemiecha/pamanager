import { Injectable, Logger } from '@nestjs/common';
import { randomBytes } from 'crypto';
import * as nodemailer from 'nodemailer';
import { DataSource } from 'typeorm';
import { urlPubblico } from '../../config/app';

const env = (k: string, d = '') => (process.env[k] ?? '') || d;

/**
 * Email in uscita, come Mailer.php: SMTP unico per tutta la piattaforma,
 * configurato solo con le variabili d'ambiente (stessi nomi del PHP).
 * Parte HTML e testo, mittente e intestazioni come prima.
 */
@Injectable()
export class MailerService {
  private readonly logger = new Logger('Email');
  private trasporto: nodemailer.Transporter | null = null;
  ultimoErrore: string | null = null;

  constructor(private readonly ds: DataSource) {}

  config() {
    return {
      enabled: env('SMTP_ENABLED', '0') === '1',
      host: env('SMTP_HOST'),
      port: Number(env('SMTP_PORT', '587')),
      encryption: env('SMTP_ENCRYPTION', 'tls').toLowerCase(),
      username: env('SMTP_USERNAME'),
      password: env('SMTP_PASSWORD'),
      fromEmail: env('SMTP_FROM_EMAIL'),
      fromName: env('SMTP_FROM_NAME', 'ConnecteedHR'),
    };
  }

  configurato(): boolean {
    const c = this.config();
    return c.enabled && !!c.host && !!c.fromEmail;
  }

  private transporter(): nodemailer.Transporter {
    if (this.trasporto) return this.trasporto;
    const c = this.config();
    this.trasporto = nodemailer.createTransport({
      host: c.host,
      port: c.port,
      // ssl = SMTPS sulla porta dedicata; tls = STARTTLS obbligatorio; none = in chiaro (solo sviluppo).
      secure: c.encryption === 'ssl',
      requireTLS: c.encryption === 'tls',
      ignoreTLS: c.encryption === 'none',
      auth: c.username ? { user: c.username, pass: c.password } : undefined,
      connectionTimeout: 15_000,
      greetingTimeout: 15_000,
      socketTimeout: 15_000,
      // Nessun file locale ne' URL nel contenuto: solo testo passato dal codice.
      disableFileAccess: true,
      disableUrlAccess: true,
    });
    return this.trasporto;
  }

  async invia(a: string, nome: string, oggetto: string, html: string, testo = ''): Promise<boolean> {
    this.ultimoErrore = null;
    const c = this.config();
    if (!c.enabled) { this.ultimoErrore = 'SMTP non abilitato'; return false; }
    if (!c.host || !c.fromEmail) { this.ultimoErrore = 'Configurazione SMTP incompleta'; return false; }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(a)) { this.ultimoErrore = 'Email destinatario non valida'; return false; }
    try {
      await this.transporter().sendMail({
        from: { name: c.fromName, address: c.fromEmail },
        to: nome ? { name: nome, address: a } : a,
        subject: oggetto,
        text: testo !== '' ? testo : html.replace(/<[^>]*>/g, '').trim(),
        html,
      });
      return true;
    } catch (e: any) {
      this.ultimoErrore = `Invio non riuscito: ${e?.message ?? e}`;
      this.logger.warn(this.ultimoErrore);
      return false;
    }
  }

  /**
   * Email a un dipendente: solo se ha un indirizzo e non ha disattivato le
   * email. Aggiunge in fondo il link per disattivarle (token persistente).
   */
  async inviaADipendente(employeeId: number, oggetto: string, html: string, testo = ''): Promise<boolean> {
    const [e] = await this.ds.query(
      'SELECT id, email, first_name, last_name, notify_email, email_unsubscribe_token FROM employees WHERE id = $1', [employeeId]);
    if (!e || !e.email || e.notify_email === false) return false;
    let token: string = e.email_unsubscribe_token;
    if (!token) {
      token = randomBytes(24).toString('hex');
      await this.ds.query('UPDATE employees SET email_unsubscribe_token = $2 WHERE id = $1', [employeeId, token])
        .catch((err) => this.logger.error(`token disiscrizione non salvato: ${err?.message ?? err}`));
    }
    const link = urlPubblico(`/employee/unsubscribe.php?token=${encodeURIComponent(token)}`);
    const footerHtml = '<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0 12px;">'
      + '<p style="color:#a0aec0;font-size:12px;line-height:1.5;">'
      + "Ricevi questa email perche' sei iscritto al portale aziendale. "
      + "Se non vuoi piu' ricevere queste notifiche, "
      + `<a href="${escapeHtml(link)}" style="color:#3182ce;">disattiva le email qui</a>.`
      + '</p>';
    const footerTesto = `\n\n---\nNon vuoi piu' ricevere queste email? Disattivale qui: ${link}`;
    return this.invia(e.email, `${e.first_name} ${e.last_name}`.trim(), oggetto, html + footerHtml, testo + footerTesto);
  }
}

export function escapeHtml(s: string): string {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}
