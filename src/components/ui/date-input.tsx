import * as React from "react";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";
import { displayToIso, isoToDisplay, maskDateDigits } from "@/lib/dates";

export interface DateInputProps extends Omit<React.ComponentProps<typeof Input>, "type" | "value" | "onChange"> {
  /** Valor em ISO yyyy-mm-dd (vazio = sem data) */
  value: string;
  onChange: (iso: string) => void;
}

const DateInput = React.forwardRef<HTMLInputElement, DateInputProps>(
  ({ className, value, onChange, onBlur, placeholder = "dd/mm/aaaa", ...props }, ref) => {
    const [text, setText] = React.useState(() => isoToDisplay(value));

    React.useEffect(() => {
      setText(isoToDisplay(value));
    }, [value]);

    const commit = (raw: string) => {
      const trimmed = raw.trim();
      if (!trimmed) {
        onChange("");
        setText("");
        return;
      }
      const iso = displayToIso(trimmed);
      if (iso === null) return;
      onChange(iso);
      setText(isoToDisplay(iso));
    };

    return (
      <Input
        ref={ref}
        type="text"
        inputMode="numeric"
        autoComplete="off"
        placeholder={placeholder}
        maxLength={10}
        className={cn(className)}
        value={text}
        onChange={(e) => {
          const masked = maskDateDigits(e.target.value);
          setText(masked);
          if (masked.length === 10) {
            const iso = displayToIso(masked);
            if (iso !== null) onChange(iso);
          }
        }}
        onBlur={(e) => {
          commit(e.target.value);
          onBlur?.(e);
        }}
        {...props}
      />
    );
  },
);
DateInput.displayName = "DateInput";

export { DateInput };
