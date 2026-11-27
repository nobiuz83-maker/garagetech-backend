# Appointment notification providers

Appointments use the existing AppointmentsService database for the notification outbox and Laravel's database queue. The OFFICE Compose profile runs the worker and scheduler as process roles of that same service.

Provider credentials are read only from `.private/office/notification-provider.env`. That file is excluded from source control and must remain readable only by the server operator. It is intentionally created with empty values; no real message is sent until a provider is configured and enabled in Settings → Notifications.

Configure SMTP with `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME`, `MAIL_FROM_ADDRESS`, and `MAIL_FROM_NAME`. Configure Twilio with `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, and `TWILIO_FROM_NUMBER`. Restart only `appointments-service`, `appointments-notification-worker`, and `appointments-scheduler` after changing the file. The UI reports only whether each provider is configured; it never returns credentials.

The scheduler dispatches pending outbox records each minute as a recovery path. New reminders are also inserted into the durable database queue with their UTC due time, so normal delivery is scheduled for the exact 24-hour instant. If the queue is unavailable at commit time, the appointment still saves and the scheduler retries the pending outbox record.

SMS delivery status remains `sent` after Twilio accepts a message. Provider delivery receipts are not configured; a verified callback endpoint is required before the UI can report `delivered`.
