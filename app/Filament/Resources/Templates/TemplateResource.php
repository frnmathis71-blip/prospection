<?php

namespace App\Filament\Resources\Templates;

use App\Enums\TemplateAngle;
use App\Filament\Resources\Templates\Pages\CreateTemplate;
use App\Filament\Resources\Templates\Pages\EditTemplate;
use App\Filament\Resources\Templates\Pages\ListTemplates;
use App\Filament\Resources\Templates\Pages\PreviewTemplate;
use App\Models\Template;
use App\Services\TemplateRenderer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Templates d'email (mission M8) : objet, corps en Markdown avec variables, angle, variante de
 * secteur et A/B. Bouton, désinscription et mentions légales sont ajoutés au rendu.
 */
class TemplateResource extends Resource
{
    protected static ?string $model = Template::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $modelLabel = 'template';

    protected static ?string $pluralModelLabel = 'templates';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Modèle')
                    ->columns(4)
                    ->schema([
                        TextInput::make('name')->label('Nom')->required()->maxLength(255)->columnSpan(2),
                        Select::make('angle')->label('Angle')->options(TemplateAngle::class)->required(),
                        Select::make('variant')
                            ->label('Variante (test A/B)')
                            ->options(['A' => 'A', 'B' => 'B'])
                            ->placeholder('Aucune'),
                        Select::make('sector')
                            ->label('Secteur')
                            ->options(self::sectorOptions())
                            ->placeholder('Tous les secteurs')
                            ->helperText('Variante réservée à un secteur (exemple de réalisation pertinent).')
                            ->columnSpan(2),
                        TextInput::make('cta_label')
                            ->label('Texte du bouton')
                            ->placeholder(config('prospection.emails.cta_label'))
                            ->maxLength(60)
                            ->helperText('Bouton unique vers '.config('prospection.emails.cta_url').' (paramètres UTM ajoutés).')
                            ->columnSpan(2),
                    ]),
                Section::make('Contenu')
                    ->schema([
                        TextInput::make('subject')
                            ->label('Objet')
                            ->required()
                            ->maxLength(150)
                            ->rule(fn () => self::knownVariablesRule()),
                        MarkdownEditor::make('body')
                            ->label('Corps')
                            ->required()
                            ->rule(fn () => self::knownVariablesRule())
                            ->toolbarButtons([['bold', 'italic'], ['bulletList', 'orderedList'], ['undo', 'redo']])
                            ->helperText('80 à 150 mots, ton humain, à ton nom. Pas de lien ni d’image : le bouton est ajouté automatiquement.'),
                        Text::make(new HtmlString(self::variablesHelp())),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nom')->searchable()->sortable()->wrap()->description(fn (Template $record) => $record->subject),
                TextColumn::make('angle')->label('Angle')->badge(),
                TextColumn::make('sector')
                    ->label('Secteur')
                    ->formatStateUsing(fn (?string $state) => self::sectorOptions()[$state] ?? $state)
                    ->placeholder('Tous'),
                TextColumn::make('variant')->label('A/B')->badge()->color('gray')->placeholder('—')->alignCenter(),
                TextColumn::make('updated_at')->label('Modifié le')->date('d/m/Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('angle')->label('Angle')->options(TemplateAngle::class),
                SelectFilter::make('sector')->label('Secteur')->options(self::sectorOptions()),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label('Aperçu')
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->tooltip('Aperçu avec un vrai prospect')
                    ->url(fn (Template $record) => self::getUrl('preview', ['record' => $record])),
                EditAction::make()->iconButton()->tooltip('Modifier'),
                ReplicateAction::make()
                    ->iconButton()
                    ->tooltip('Dupliquer (variante B, secteur…)')
                    ->mutateRecordDataUsing(fn (array $data) => ['name' => $data['name'].' (copie)'] + $data),
                DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Supprimer')
                    // Un template utilisé par une séquence ne peut pas être supprimé (historique des envois).
                    ->hidden(fn (Template $record) => $record->sequenceSteps()->exists()),
            ]);
    }

    /**
     * @return array<string, string>
     */
    public static function sectorOptions(): array
    {
        return collect(config('prospection.sectors'))->map(fn (array $sector) => $sector['label'])->all();
    }

    /**
     * Refuse une variable inconnue (« {nom_entrepirse} ») dès l'enregistrement.
     */
    public static function knownVariablesRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            preg_match_all('/\{([a-z_]+)(?:\|[^{}\n]*)?\}/u', (string) $value, $matches);

            foreach (array_unique($matches[1]) as $name) {
                if (! array_key_exists($name, TemplateRenderer::VARIABLES)) {
                    $fail("La variable {{$name}} n’existe pas.");
                }
            }
        };
    }

    private static function variablesHelp(): string
    {
        $items = collect(TemplateRenderer::VARIABLES)
            ->map(fn (string $label, string $name) => '<li><code>{'.e($name).'}</code> : '.e($label).'</li>')
            ->implode('');

        return '<div style="font-size:0.875rem;line-height:1.5">'
            .'<strong>Variables</strong> — valeur de secours si la donnée manque : <code>{prenom_dirigeant|Madame, Monsieur}</code>. '
            .'Une variable vide sans valeur de secours bloque l’envoi.'
            .'<ul style="list-style:disc;padding-left:1.25rem;margin-top:0.25rem">'.$items.'</ul>'
            .'<p style="margin-top:0.25rem">Ajoutés automatiquement : bouton, identité Codenyr (nom, adresse, SIRET), mention d’information, '
            .'lien de confidentialité et désinscription en un clic.</p></div>';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTemplates::route('/'),
            'create' => CreateTemplate::route('/create'),
            'edit' => EditTemplate::route('/{record}/edit'),
            'preview' => PreviewTemplate::route('/{record}/apercu'),
        ];
    }
}
