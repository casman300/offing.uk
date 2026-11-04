/** Barometer's framing and signposting (spec §26), shown on every Barometer page that describes the log. */
export default function BarometerCareNote() {
    return (
        <p className="text-muted-foreground border-t pt-4 text-xs leading-relaxed">
            Barometer follows a common CBT (cognitive behavioural therapy) anxiety-tracking format. It works best alongside a therapist, and isn’t a
            substitute for care. If you’re struggling right now, Samaritans are there day or night on{' '}
            <a href="tel:116123" className="text-foreground underline underline-offset-2">
                116 123
            </a>{' '}
            (free, UK).
        </p>
    );
}
