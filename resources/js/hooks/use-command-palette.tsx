import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useState,
} from 'react';
import type { ReactNode } from 'react';
import { CommandPalette } from '@/components/domain/command-palette';

type CommandPaletteContextValue = {
    open: boolean;
    setOpen: (open: boolean) => void;
    toggle: () => void;
};

const CommandPaletteContext = createContext<CommandPaletteContextValue | null>(
    null,
);

/**
 * Mounts the ⌘K command palette once at the app root and exposes an
 * imperative handle so any component can open/close it (e.g. a header
 * button or a keyboard shortcut in a nested component).
 */
export function CommandPaletteProvider({ children }: { children: ReactNode }) {
    const [open, setOpen] = useState(false);

    const toggle = useCallback(() => setOpen((value) => !value), []);

    // Global shortcut — ⌘K on macOS, Ctrl+K elsewhere. Skip when the user
    // is typing in an input/textarea (e.g. search field on the roster).
    useEffect(() => {
        const handler = (event: KeyboardEvent): void => {
            if (event.key !== 'k' || !(event.metaKey || event.ctrlKey)) {
                return;
            }

            event.preventDefault();
            toggle();
        };
        window.addEventListener('keydown', handler);

        return () => window.removeEventListener('keydown', handler);
    }, [toggle]);

    return (
        <CommandPaletteContext.Provider value={{ open, setOpen, toggle }}>
            {children}
            <CommandPalette open={open} onOpenChange={setOpen} />
        </CommandPaletteContext.Provider>
    );
}

export function useCommandPalette(): CommandPaletteContextValue {
    const ctx = useContext(CommandPaletteContext);

    if (ctx === null) {
        throw new Error(
            'useCommandPalette must be used inside a <CommandPaletteProvider>',
        );
    }

    return ctx;
}
