import { usePage } from "@inertiajs/react";
import { useContext } from "react";
import { ThemeContext } from "@/Components/ThemeContext";
import { cn } from "@/lib/utils";
import { Button } from "@/Components/ui/button";
import { LogIn, Sun, Moon, CalendarClock } from "lucide-react";

export default function GuestLayout({ children }) {
    const { app_name, display_name } = usePage().props;
    const { theme, toggleTheme } = useContext(ThemeContext);
    const isDark = theme === "dark";

    const goToLogin = () => {
        window.location.href = `/${app_name}`;
    };

    const formattedAppName = display_name
        ?.split(" ")
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(" ");

    return (
        <div className="min-h-screen flex flex-col bg-zinc-100 dark:bg-zinc-950">
            <nav className="sticky top-0 z-50 bg-background/70 backdrop-blur-md border-b border-border/40 shadow-sm">
                <div className="px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between h-[54px]">
                        {/* ── App identity (copied from Sidebar's Logo block) ── */}
                        <div className="flex items-center gap-3 min-w-0">
                            <div className="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 bg-primary shadow-md">
                                <CalendarClock className="w-4 h-4 text-primary-foreground" />
                            </div>

                            <div className="flex flex-col min-w-0">
                                <span className="text-sm font-semibold tracking-tight leading-tight truncate text-foreground">
                                    {formattedAppName || app_name}
                                </span>
                                <span className="text-[7px] font-medium tracking-widest uppercase text-primary/70">
                                    Telford Svc. Phils. Inc.
                                </span>
                            </div>
                        </div>

                        {/* ── Right side ── */}
                        <div className="flex items-center gap-2">
                            {/* Theme Toggle Pill */}
                            <button
                                onClick={toggleTheme}
                                aria-label="Toggle theme"
                                className={cn(
                                    "relative flex items-center gap-1 px-1 py-1 rounded-full border transition-all duration-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
                                    isDark
                                        ? "bg-zinc-800/80 border-zinc-700/60 hover:bg-zinc-700/80"
                                        : "bg-amber-50/80 border-amber-200/70 hover:bg-amber-100/80",
                                )}
                            >
                                <span
                                    className={cn(
                                        "absolute top-1 w-6 h-6 rounded-full shadow-md transition-all duration-300 ease-in-out",
                                        isDark
                                            ? "left-1 bg-gradient-to-br from-indigo-500 to-violet-600 shadow-indigo-900/50"
                                            : "left-[calc(100%-1.75rem)] bg-gradient-to-br from-amber-400 to-orange-500 shadow-amber-400/40",
                                    )}
                                />
                                <span
                                    className={cn(
                                        "relative z-10 flex items-center justify-center w-6 h-6 transition-all duration-300",
                                        isDark ? "text-zinc-500" : "text-white",
                                    )}
                                >
                                    <Sun className="w-3.5 h-3.5" />
                                </span>
                                <span
                                    className={cn(
                                        "relative z-10 flex items-center justify-center w-6 h-6 transition-all duration-300",
                                        isDark
                                            ? "text-white"
                                            : "text-amber-300/60",
                                    )}
                                >
                                    <Moon className="w-3.5 h-3.5" />
                                </span>
                            </button>

                            {/* Thin Divider */}
                            <div className="w-px h-5 bg-border/50 mx-1" />

                            {/* Staff Login button — hard redirect, not Inertia Link,
                                since AuthMiddleware issues a non-Inertia
                                Inertia::location() redirect to Authify */}
                            <Button
                                onClick={goToLogin}
                                variant="ghost"
                                className="flex items-center gap-2 px-3 py-1.5 h-auto rounded-full hover:bg-muted/60 focus-visible:ring-0 focus-visible:ring-offset-0"
                            >
                                <LogIn className="w-4 h-4 text-muted-foreground" />
                                <span className="text-sm font-medium">
                                    Staff Login
                                </span>
                            </Button>
                        </div>
                    </div>
                </div>
            </nav>

            <main className="flex-1 min-h-0 overflow-hidden">{children}</main>
        </div>
    );
}
