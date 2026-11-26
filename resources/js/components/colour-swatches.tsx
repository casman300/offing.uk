/** Pick one colour from a palette — calendars and subscriptions use the same one. */
export default function ColourSwatches({
    colours,
    value,
    onChange,
    label,
}: {
    colours: string[];
    value: string;
    onChange: (colour: string) => void;
    label: string;
}) {
    return (
        <div className="flex flex-wrap gap-1.5" role="radiogroup" aria-label={label}>
            {colours.map((colour) => (
                <button
                    key={colour}
                    type="button"
                    role="radio"
                    aria-checked={value === colour}
                    aria-label={colour}
                    onClick={() => onChange(colour)}
                    className="ring-offset-background aria-checked:ring-ring size-6 rounded-full aria-checked:ring-2 aria-checked:ring-offset-2"
                    style={{ backgroundColor: colour }}
                />
            ))}
        </div>
    );
}
