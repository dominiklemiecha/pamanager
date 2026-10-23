import { Module } from '@nestjs/common';
import { APP_GUARD } from '@nestjs/core';
import { AuthController } from './auth.controller';
import { AuthService } from './auth.service';
import { PasswordService } from './password.service';
import { SessioneGuard } from './sessione.guard';
import { SessioniService } from './sessioni.service';
import { TenantService } from './tenant.service';

@Module({
  controllers: [AuthController],
  providers: [AuthService, PasswordService, SessioniService, TenantService, { provide: APP_GUARD, useClass: SessioneGuard }],
  exports: [AuthService, PasswordService, SessioniService, TenantService],
})
export class AuthModule {}
