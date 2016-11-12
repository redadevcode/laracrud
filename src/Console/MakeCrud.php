<?php

namespace Mrdebug\Crudgen\Console;

use Illuminate\Console\Command;

use File;

class MakeCrud extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:crud {crud_name} {columns}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crud gen';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        
        $template_views_directory=config('crudgen.views_style_directory');
        $separate_style_according_to_actions=config('crudgen.separate_style_according_to_actions');

        // we create our variables to respect the naming conventions
        $crud_name = ucfirst($this->argument('crud_name'));
        $plural_name=str_plural($crud_name);
        $singular_name=str_singular($crud_name);
        $singular_low_name=str_singular(strtolower($crud_name));
        $plural_low_name=str_plural(strtolower($crud_name));

        /* ************************************************************************* 

                                     CONTROLLER

        ************************************************************************* */

        $controller_stub= File::get($this->getStubPath().'/Controller.stub');
        $controller_stub = str_replace('DummyClass', $plural_name.'Controller', $controller_stub);
        $controller_stub = str_replace('DummyModel', $singular_name, $controller_stub);
        $controller_stub = str_replace('DummyVariableSing', $singular_low_name, $controller_stub);
        $controller_stub = str_replace('DummyVariable', $plural_low_name, $controller_stub);
        $controller_stub = str_replace('DummyNamespace', $this->getDefaultNamespaceController($this->laravel->getNamespace()), $controller_stub);
        $controller_stub = str_replace('DummyRootNamespace', $this->laravel->getNamespace(), $controller_stub);

        $columns = $this->argument('columns');
        // if the columns argument is empty, we create an empty array else we explode on the comma
        if($columns=='')
            $columns=[];
        else
            $columns=explode(',', $columns);

        $cols=$rules=$fields_migration='';

        // we create our placeholders regarding columns
        foreach ($columns as $column) 
        {
            $type=explode(':', trim($column));

            if(count($type)==2)
                $sql_type=$type[1];
            else
                $sql_type='string';

            $column=$type[0];

            // our placeholders
            $cols.=str_repeat("\t", 2).'DummyCreateVariableSing$->'.trim($column).'=$request->input(\''.trim($column).'\');'."\n";
            $rules .=str_repeat("\t", 3)."'".trim($column)."'=>'"."required',\n";
            $fields_migration .=str_repeat("\t", 3).'$table'."->$sql_type('".trim($column)."');\n";
        }

        // we replace our placeholders
        $controller_stub = str_replace('DummyUpdate', $cols, $controller_stub);
        $controller_stub = str_replace('DummyCreateVariable$', '$'.$plural_low_name, $controller_stub);
        $controller_stub = str_replace('DummyCreateVariableSing$', '$'.$singular_low_name, $controller_stub);

        // if our controller doesn't exists we create it 
        if(!File::exists($this->getRealpathBase('app/Http/Controllers').'/'.$plural_name.'Controller.php'))
        {
            File::put($this->getRealpathBase('app/Http/Controllers').'/'.$plural_name.'Controller.php', $controller_stub);
            $this->line("<info>Created Controller:</info> $plural_name");
            
        }
        else
            $this->error('Controller '.$plural_name.' already exists');


        /* ************************************************************************* 

                                        VIEWS

        ************************************************************************* */

        $this->call
        (
            'make:views',
            [
                'directory'=> $crud_name,
                'columns'=> $this->argument('columns')
            ]
        );
        

        /* ************************************************************************* 

                                        REQUEST

        ************************************************************************* */

        $request_stub= File::get($this->getStubPath().'/Request.stub');
        $request_stub = str_replace('DummyNamespace', $this->getDefaultNamespaceRequest($this->laravel->getNamespace()), $request_stub);
        $request_stub = str_replace('DummyRootNamespace', $this->laravel->getNamespace(), $request_stub);    
        $request_stub = str_replace('DummyRulesRequest', $rules, $request_stub);
        $request_stub = str_replace('DummyClass', $singular_name.'Request', $request_stub);
        
        // if the Request file doesn't exist, we create it
        if(!File::exists($this->getRealpathBase('app/Http/Requests').'/'.$singular_name.'Request.php'))
        {
            File::put($this->getRealpathBase('app/Http/Requests').'/'.$singular_name.'Request.php', $request_stub);
            $this->line("<info>Created Request:</info> $singular_name");
        }
        else
            $this->error('Request ' .$singular_name. ' already exists');

        /* ************************************************************************* 

                                        MODEL

        ************************************************************************* */

        // we create our model

            $this->createRelationships([], $singular_name);


        /* ************************************************************************* 

                                        MIGRATION

        ************************************************************************* */

        $migration_stub= File::get($this->getStubPath().'/migration.stub');
        $table=str_plural(snake_case($crud_name));
        $migration_stub= str_replace('DummyTable', $table, $migration_stub);
        $migration_stub= str_replace('DummyClass', studly_case('create_' . $table . '_table'), $migration_stub);
        $migration_stub= str_replace('DummyFields', $fields_migration, $migration_stub);
        $date = date('Y_m_d_His');
        
        File::put(database_path('/migrations/') . $date . '_create_' . $table . '_table.php', $migration_stub);

        $this->line("<info>Created Migration:</info> $date"."_create_".$table."_table.php");
    }

    private function createRelationships($infos, $singular_name)
    {
        if ($this->confirm('Do you want to create relationships between this model and an other one?')) 
        {
            $type = $this->choice(
                'Which type?', 
                ['belongsTo', 'hasOne', 'hasMany', 'Cancel']
            );

            if($type=="Cancel")
                $this->call('make:model', ['name' => $singular_name]);
            else
                $this->setOtherNameModelRelationship($type, $singular_name);
        }
        else
        {
            if(empty($infos))
                $this->call('make:model', ['name' => $singular_name]);
            else
            {
                $all_relations='';
                foreach ($infos as $key => $info) 
                {
                    if($info['type']=="hasMany")
                        $name_function=str_plural(strtolower($info['name']));
                    else
                        $name_function=str_singular(strtolower($info['name']));

                    $all_relations .=str_repeat("\t", 1).'public function '.$name_function.'()'."\n";
                    $all_relations .= str_repeat("\t", 1).'{'."\n";
                    $all_relations .=str_repeat("\t", 3).'return $this->'.$info['type'].'(\''.$this->laravel->getNamespace().''.ucfirst(str_singular($info['name'])).'\');'."\n";
                    $all_relations .= str_repeat("\t", 1).'}'."\n\n";
                }

                $model_stub= File::get($this->getStubPath().'/model.stub');
                $model_stub = str_replace('DummyNamespace', trim($this->laravel->getNamespace(), '\\'), $model_stub);
                $model_stub = str_replace('DummyClass', $singular_name, $model_stub);
                $model_stub = str_replace('DummyRelations', $all_relations, $model_stub);

                if(!File::exists($this->getRealpathBase('app/').'/'.$singular_name.'.php'))
                {

                    File::put($this->getRealpathBase('app/').'/'.$singular_name.'.php', $model_stub);
                    $this->line("<info>Created Model:</info> $singular_name");
                }
                else
                    $this->error('Model ' .$singular_name. ' already exists');
                
            }

            
        }
    }

    private function setOtherNameModelRelationship($type, $singular_name)
    {
        $name_other_model = $this->ask('What is the name of the other model?');
        if($this->confirm('Do you confirm the creation of this relationship? "$this->'.$type.'(\''.$this->laravel->getNamespace().''.ucfirst(str_singular($name_other_model)).'\')"'))
        {
            $infos[]= ['name'=>$name_other_model, 'type'=>$type];
            $this->createRelationships($infos, $singular_name);
        }
        else
            $this->setOtherNameModelRelationship($type, $singular_name);
    }

    private function getStubPath()
    {

        return __DIR__.'/../stubs';
    }

    protected function getDefaultNamespaceController($rootNamespace)
    {
        return $rootNamespace.'Http\Controllers';
    }

    protected function getDefaultNamespaceRequest($rootNamespace)
    {
        return $rootNamespace.'Http\Requests';
    }

    protected function getRealpathBase($directory)
    {
        return realpath(base_path($directory));
    }
}