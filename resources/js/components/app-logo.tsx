import AppLogoIcon from '@/components/app-logo-icon';
import AppLogoWordmark from '@/components/app-logo-wordmark';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center">
                <AppLogoIcon className="size-7" />
            </div>
            <div className="ml-1 flex min-w-0 flex-1 items-center">
                <AppLogoWordmark className="h-4" />
            </div>
        </>
    );
}
