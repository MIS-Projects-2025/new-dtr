import { usePage } from "@inertiajs/react";
import { useState, useContext } from "react";
import { ThemeContext } from "./ThemeContext";
import { cn } from "@/lib/utils";
import { Button } from "@/Components/ui/button";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from "@/Components/ui/dropdown-menu";
import { Avatar, AvatarFallback } from "@/Components/ui/avatar";
import { ChevronDown, User, LogOut, Loader2, Sun, Moon } from "lucide-react";

export default function NavBar() {
    const { emp_data } = usePage().props;
    const { theme, toggleTheme } = useContext(ThemeContext);
    const [isLoggingOut, setIsLoggingOut] = useState(false);

    const logout = () => {
        setIsLoggingOut(true);
        localStorage.clear();
        sessionStorage.clear();
        setTimeout(() => {
            window.location.href = route("logout");
        }, 500);
    };

    const getInitials = (name) => {
        if (!name) return "?";
        return name
            .split(" ")
            .map((n) => n[0])
            .join("")
            .toUpperCase()
            .slice(0, 2);
    };

    const firstName = emp_data?.emp_firstname || "Guest";
    const isDark = theme === "dark";

    return (
        <nav className="sticky top-0 z-50 bg-background/70 backdrop-blur-md border-b border-border/40 shadow-sm">
            <div className="px-4 sm:px-6 lg:px-8">
                <div className="flex items-center justify-end h-[54px] gap-2">
                    {/* ── Theme Toggle Pill ── */}
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
                        {/* Sliding indicator */}
                        <span
                            className={cn(
                                "absolute top-1 w-6 h-6 rounded-full shadow-md transition-all duration-300 ease-in-out",
                                isDark
                                    ? "left-1 bg-gradient-to-br from-indigo-500 to-violet-600 shadow-indigo-900/50"
                                    : "left-[calc(100%-1.75rem)] bg-gradient-to-br from-amber-400 to-orange-500 shadow-amber-400/40",
                            )}
                        />

                        {/* Sun icon */}
                        <span
                            className={cn(
                                "relative z-10 flex items-center justify-center w-6 h-6 transition-all duration-300",
                                isDark ? "text-zinc-500" : "text-white",
                            )}
                        >
                            <Sun className="w-3.5 h-3.5" />
                        </span>

                        {/* Moon icon */}
                        <span
                            className={cn(
                                "relative z-10 flex items-center justify-center w-6 h-6 transition-all duration-300",
                                isDark ? "text-white" : "text-amber-300/60",
                            )}
                        >
                            <Moon className="w-3.5 h-3.5" />
                        </span>
                    </button>

                    {/* ── Thin Divider ── */}
                    <div className="w-px h-5 bg-border/50 mx-1" />

                    {/* ── User Dropdown ── */}
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                className="flex items-center gap-2.5 px-2.5 py-1.5 h-auto rounded-full hover:bg-muted/60 focus-visible:ring-0 focus-visible:ring-offset-0"
                            >
                                <div className="relative">
                                    <Avatar className="w-8 h-8">
                                        <AvatarFallback
                                            className={cn(
                                                "text-xs font-bold",
                                                isDark
                                                    ? "bg-gradient-to-br from-indigo-500 to-violet-600 text-white"
                                                    : "bg-gradient-to-br from-amber-400 to-orange-500 text-white",
                                            )}
                                        >
                                            {getInitials(firstName)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <span className="absolute bottom-0 right-0 w-2.5 h-2.5 bg-emerald-500 border-2 border-background rounded-full" />
                                </div>

                                <span className="text-sm font-medium hidden sm:block">
                                    Hello,{" "}
                                    <span className="font-semibold">
                                        {firstName}
                                    </span>
                                </span>

                                <ChevronDown className="w-3.5 h-3.5 text-muted-foreground transition-transform duration-200 group-data-[state=open]:rotate-180" />
                            </Button>
                        </DropdownMenuTrigger>

                        <DropdownMenuContent
                            align="end"
                            sideOffset={8}
                            className="w-56 rounded-2xl p-1.5 shadow-xl"
                        >
                            {/* Profile header */}
                            <div className="flex items-center gap-3 px-3 py-2.5">
                                <Avatar className="w-9 h-9">
                                    <AvatarFallback
                                        className={cn(
                                            "text-sm font-bold",
                                            isDark
                                                ? "bg-gradient-to-br from-indigo-500 to-violet-600 text-white"
                                                : "bg-gradient-to-br from-amber-400 to-orange-500 text-white",
                                        )}
                                    >
                                        {getInitials(firstName)}
                                    </AvatarFallback>
                                </Avatar>
                                <div className="flex flex-col min-w-0">
                                    <span className="text-sm font-semibold leading-tight truncate">
                                        {firstName}
                                    </span>
                                    <span className="text-xs text-emerald-500 font-medium flex items-center gap-1 mt-0.5">
                                        <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 inline-block" />
                                        Active now
                                    </span>
                                </div>
                            </div>

                            <DropdownMenuSeparator className="my-1" />

                            <DropdownMenuItem
                                asChild
                                className="rounded-xl cursor-pointer gap-3 px-3 py-2"
                            >
                                <a href={route("profile.index")}>
                                    <User className="w-4 h-4 text-muted-foreground" />
                                    <span className="text-sm">Profile</span>
                                </a>
                            </DropdownMenuItem>

                            <DropdownMenuItem
                                onClick={logout}
                                disabled={isLoggingOut}
                                className="rounded-xl cursor-pointer gap-3 px-3 py-2 text-destructive focus:text-destructive focus:bg-destructive/10"
                            >
                                {isLoggingOut ? (
                                    <Loader2 className="w-4 h-4 animate-spin" />
                                ) : (
                                    <LogOut className="w-4 h-4" />
                                )}
                                <span className="text-sm">
                                    {isLoggingOut ? "Signing out…" : "Log out"}
                                </span>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
        </nav>
    );
}
