export default function GuestLayout({ children }) {
    return (
        <div className="h-screen w-screen overflow-hidden bg-zinc-100 dark:bg-zinc-950">
            <main className="h-full w-full">{children}</main>
        </div>
    );
}
