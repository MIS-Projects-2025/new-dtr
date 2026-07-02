import { usePage } from "@inertiajs/react";
import { useContext } from "react";
import { ThemeContext } from "@/Components/ThemeContext";
import { cn } from "@/lib/utils";
import { Button } from "@/Components/ui/button";
import { LogIn, Sun, Moon } from "lucide-react";

export default function GuestLayout({ children }) {
    const { app_name } = usePage().props;
    const { theme, toggleTheme } = useContext(ThemeContext);
    const isDark = theme === "dark";

    const goToLogin = () => {
        window.location.href = `/${app_name}`;
    };

    return (
        <div className="h-screen w-screen overflow-hidden bg-zinc-100 dark:bg-zinc-950">
            {/* ── Floating controls (theme toggle + staff login), no nav bar ── */}
            <div className="fixed top-4 right-4 z-50 flex items-center gap-2">
                <button
                    onClick={toggleTheme}
                    aria-label="Toggle theme"
                    className={cn(
                        "relative flex items-center gap-1 px-1 py-1 rounded-full border shadow-md transition-all duration-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
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
                            isDark ? "text-white" : "text-amber-300/60",
                        )}
                    >
                        <Moon className="w-3.5 h-3.5" />
                    </span>
                </button>

                <Button
                    onClick={goToLogin}
                    variant="ghost"
                    size="icon"
                    className="h-9 w-9 rounded-full shadow-md bg-background/80 backdrop-blur-md border border-border/40 hover:bg-muted/60 focus-visible:ring-0 focus-visible:ring-offset-0"
                    title="Staff Login"
                >
                    <LogIn className="w-4 h-4 text-muted-foreground" />
                </Button>
            </div>

            <main className="h-full w-full">{children}</main>
        </div>
    );
}
