<?php

declare(strict_types=1);

namespace Modules\Settings\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Modules\Settings\Actions\SaveStoreLogo;
use Modules\Settings\Actions\SaveStoreSettings;
use Modules\Settings\Contracts\FeatureFlags;
use Modules\Settings\Contracts\StoreSettings;
use Modules\Settings\Data\StoreSettingsData;
use Modules\Settings\Support\StoreLogo;
use UnitEnum;

/**
 * The store's identity and configuration, owned by the admin rather than code or `.env`:
 * name, storefront URL, logo, locale, theme, tax display, SEO, mail sender and feature flags.
 * Saving goes through SaveStoreSettings / SaveStoreLogo, which check settings.manage.
 *
 * @property-read Schema $form
 */
final class StoreSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Store';

    protected static ?string $title = 'Store settings';

    protected static ?string $slug = 'settings/store';

    protected static ?int $navigationSort = 1;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return self::actor()?->can('settings.manage') ?? false;
    }

    public function mount(): void
    {
        $settings = app(StoreSettings::class);

        $this->form->fill([
            'name' => $settings->string('store.name'),
            'url' => $settings->string('store.url'),
            'timezone' => $settings->string('store.timezone', 'Asia/Karachi'),
            'locale' => $settings->string('store.locale', 'en'),
            'country' => $settings->string('store.country', 'PK'),
            'contact_email' => $settings->get('store.contact_email'),
            'contact_phone' => $settings->get('store.contact_phone'),
            'theme_id' => $settings->string('theme.id', 'default'),
            'theme_tokens' => (array) ($settings->get('theme.tokens') ?? []),
            'tax_mode' => $settings->string('tax.mode', 'inclusive'),
            'allow_indexing' => (bool) $settings->get('seo.robots_allow_indexing', true),
            'title_suffix' => $settings->get('seo.title_suffix'),
            'mail_from_name' => $settings->get('mail.from_name'),
            'mail_from_address' => $settings->get('mail.from_address'),
            'features' => app(FeatureFlags::class)->all(),
            'logo' => null,
            'remove_logo' => false,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $settings = app(StoreSettings::class);
        $logo = StoreLogo::url($settings->string('store.logo'));

        return $schema
            ->statePath('data')
            ->components([
                Section::make('Store')->columns(2)->schema([
                    TextInput::make('name')->label('Store name')->required()->maxLength(120),
                    TextInput::make('url')->label('Storefront URL')->required()->url()->maxLength(255)
                        ->helperText('Public address of the shop, e.g. https://velvinahome.com. Used in emails and for page refreshes.'),
                    TextInput::make('currency')->label('Currency')->disabled()->dehydrated(false)
                        ->formatStateUsing(fn (): string => $settings->string('store.currency', 'PKR'))
                        ->helperText('Fixed at installation: prices and orders are stored in it.'),
                    Select::make('timezone')->required()->searchable()
                        ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list())),
                    TextInput::make('locale')->required()->maxLength(5)->regex('/^[a-z]{2}(-[A-Z]{2})?$/'),
                    TextInput::make('country')->label('Country code')->required()->length(2)->regex('/^[A-Z]{2}$/'),
                    TextInput::make('contact_email')->email()->maxLength(255),
                    TextInput::make('contact_phone')->tel()->maxLength(40),
                ]),
                Section::make('Logo')->columns(2)->schema([
                    FileUpload::make('logo')->label('New logo')->image()->storeFiles(false)
                        ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/avif'])
                        ->maxSize(intdiv(SaveStoreLogo::MAX_BYTES, 1024))
                        ->helperText($logo === null ? 'No logo yet. PNG, JPEG, WebP or AVIF, up to 2 MB.' : 'Current logo: '.$logo),
                    Toggle::make('remove_logo')->label('Remove the current logo')->visible($logo !== null),
                ]),
                Section::make('Theme')->columns(2)->schema([
                    TextInput::make('theme_id')->label('Theme')->required()->maxLength(60)->regex('/^[a-z0-9]+(-[a-z0-9]+)*$/')
                        ->helperText('A theme shipped with the storefront, e.g. default or velvina-home.'),
                    KeyValue::make('theme_tokens')->label('Design token overrides')->keyLabel('Token')->valueLabel('Value')
                        ->helperText('Optional, e.g. color-accent → #8a5a44.'),
                ]),
                Section::make('Prices, search engines and email')->columns(2)->schema([
                    Select::make('tax_mode')->label('Prices are shown')->required()
                        ->options(['inclusive' => 'Including tax', 'exclusive' => 'Excluding tax']),
                    Toggle::make('allow_indexing')->label('Allow search engines to index the store'),
                    TextInput::make('title_suffix')->label('Page title suffix')->maxLength(120),
                    TextInput::make('mail_from_name')->label('Email sender name')->maxLength(120),
                    TextInput::make('mail_from_address')->label('Email sender address')->email()->maxLength(255),
                ]),
                Section::make('Features')->columns(3)->schema(array_map(
                    fn (string $flag): Toggle => Toggle::make('features.'.$flag)->label(Str::headline($flag)),
                    array_keys(app(FeatureFlags::class)->all()),
                )),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label('Save')->submit('save')->keyBindings(['mod+s'])])]),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $actor = self::actor();

        app(SaveStoreSettings::class)->handle(StoreSettingsData::validateAndCreate([
            'name' => $state['name'],
            'url' => $state['url'],
            'timezone' => $state['timezone'],
            'locale' => $state['locale'],
            'country' => $state['country'],
            'contactEmail' => $state['contact_email'] ?: null,
            'contactPhone' => $state['contact_phone'] ?: null,
            'themeId' => $state['theme_id'],
            'themeTokens' => $state['theme_tokens'] ?? [],
            'taxMode' => $state['tax_mode'],
            'allowIndexing' => (bool) $state['allow_indexing'],
            'titleSuffix' => $state['title_suffix'] ?: null,
            'mailFromName' => $state['mail_from_name'] ?: null,
            'mailFromAddress' => $state['mail_from_address'] ?: null,
            'features' => $state['features'] ?? [],
        ]), $actor);

        $upload = collect((array) ($state['logo'] ?? []))->first();

        if ($upload instanceof TemporaryUploadedFile) {
            app(SaveStoreLogo::class)->handle($upload->getRealPath(), $actor);
        } elseif ((bool) ($state['remove_logo'] ?? false)) {
            app(SaveStoreLogo::class)->handle(null, $actor);
        }

        Notification::make()->success()->title('Store settings saved')->send();
        $this->mount();
    }

    private static function actor(): ?Authorizable
    {
        $user = auth('staff')->user();

        return $user instanceof Authorizable ? $user : null;
    }
}
