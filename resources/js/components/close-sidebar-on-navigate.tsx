import { useEffect } from 'react';
import { useSidebar } from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';

/**
 * On phones the sidebar is a drawer over the page: once the user picks a
 * destination it gets out of the way. Keyed on the path, so live reloads of
 * the same screen (useLiveReload) don't close a drawer the user just opened.
 */
export function CloseSidebarOnNavigate() {
    const { currentUrl } = useCurrentUrl();
    const { setOpenMobile } = useSidebar();

    useEffect(() => {
        setOpenMobile(false);
    }, [currentUrl, setOpenMobile]);

    return null;
}
