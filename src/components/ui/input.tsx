import * as React from "react";

import { cn } from "@/lib/utils";

export interface InputProps extends React.ComponentProps<"input"> {
  variant?: "default" | "search" | "hero";
}

const Input = React.forwardRef<HTMLInputElement, InputProps>(
  ({ className, type, variant = "default", ...props }, ref) => {
    const variants = {
      default: "h-10 border-input bg-background",
      search: "h-12 border-border bg-card pl-12 shadow-card focus:shadow-card-hover",
      hero: "h-14 border-transparent bg-card/80 backdrop-blur-sm pl-14 text-base shadow-lg focus:shadow-glow",
    };

    return (
      <input
        type={type}
        className={cn(
          "flex w-full rounded-xl border px-4 py-2 text-base ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 transition-all duration-200",
          variants[variant],
          className,
        )}
        ref={ref}
        {...props}
      />
    );
  },
);
Input.displayName = "Input";

export { Input };
