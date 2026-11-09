/** Human wording for status keys flashed by Fortify and our controllers. Unknown values are shown as sent. */
const messages: Record<string, string> = {
    'verification-link-sent': 'We sent a new verification link to your email.',
    'code-sent': 'We texted you a new 6-digit code.',
    'phone-verified': 'Your mobile number is verified.',
    'two-factor-authentication-enabled': 'Scan the QR code with your authenticator app, then enter the 6-digit code to finish.',
    'two-factor-authentication-confirmed': 'Two-step sign-in is on. Save your recovery codes somewhere safe.',
    'two-factor-authentication-disabled': 'Two-step sign-in is off.',
    'recovery-codes-generated': 'New recovery codes created. Your old ones no longer work.',
    'password-updated': 'Your password has been changed.',
    'device-removed': 'Device removed.',
    'other-devices-signed-out': 'You’ve been signed out everywhere else.',
};

export const statusMessage = (status?: string | null) => (status ? (messages[status] ?? status) : null);
