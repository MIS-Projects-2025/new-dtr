import * as React from "react";
import { cva } from "class-variance-authority";
import { Slot } from "@radix-ui/react-slot";
import { cn } from "@/lib/utils";

const buttonVariants = cva(
  "group/button inline-flex ...", // keep your styles
  {
    variants: {
      variant: {
        default: "bg-primary text-primary-foreground hover:bg-primary/80",
        outline: "border-border bg-background hover:bg-muted",
        secondary: "bg-secondary text-secondary-foreground",
        ghost: "hover:bg-muted",
        destructive: "bg-destructive/10 text-destructive",
        link: "text-primary underline-offset-4 hover:underline",
      },
      size: {
        default: "h-9 px-3",
        sm: "h-8 px-3",
        lg: "h-10 px-4",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
);

// ✅ THIS is the important part
const Button = React.forwardRef(
  (
    { className, variant = "default", size = "default", asChild = false, ...props },
    ref // ✅ ref is received here
  ) => {
    const Comp = asChild ? Slot : "button";

    return (
      <Comp
        ref={ref} // ✅ now this works
        data-slot="button"
        data-variant={variant}
        data-size={size}
        className={cn(buttonVariants({ variant, size, className }))}
        {...props}
      />
    );
  }
);

Button.displayName = "Button";

export { Button, buttonVariants };