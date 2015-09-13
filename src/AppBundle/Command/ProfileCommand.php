<?php

namespace AppBundle\Command;

use Sensio\Bundle\FrameworkExtraBundle\EventListener\ControllerListener;
use Sensio\Bundle\FrameworkExtraBundle\EventListener\ParamConverterListener;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Route;

class ProfileCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this->setName('app:profile');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output = new SymfonyStyle($input, $output);
        $router = $this->getContainer()->get('router');
        $routes = $router->getRouteCollection();
        $client = $this->getContainer()->get('test.client');

        $users = array(
            '~' => false,
            'ROLE_USER' => array('username' => 'john_user', 'password' => 'kitten'),
            'ROLE_ADMIN' => array('username' => 'anna_admin', 'password' => 'kitten'),
        );

        /** @var Route $route */
        foreach ($routes as $name => $route) {

            $output->writeln(sprintf('<info>%s</info> <comment>%s</comment>', $name, $route->getPath()));

            // Skip core routes beginning by _
            if (substr($name, 0, 1) === '_') {
                $output->writeln('Skip core route');
                continue;
            }

            // Build params in query
            $params = array();
            foreach ($route->compile()->getPathVariables() as $variable) {
                $params[$variable] = ($default = $route->getDefault($variable)) ? $default : 1;
            }

            // Generate route
            try {
                $generated = $router->generate($name, $params, UrlGenerator::ABSOLUTE_URL);
            } catch (\Exception $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                continue;
            }

            $headers = array('url', 'role', 'method', 'response', 'duration', 'memory usage', 'query count', 'token');
            $rows = array();
            foreach ($users as $role => $user) {

                $servers = array();
                if ($user) {
                    $servers['PHP_AUTH_USER'] = $user['username'];
                    $servers['PHP_AUTH_PW'] = $user['password'];
                }

                foreach (($methods = $route->getMethods()) ? $methods : array('GET') as $method) {

                    // Skip delete actions
                    if ($method === 'DELETE') {
                        continue;
                    }

                    // request
                    $client->restart();
                    $client->enableProfiler();
                    $client->request($method, $generated, array(), array(), $servers);

                    // output
                    $row = array($generated, $role, $method, $this->writeCode($client));
                    if ($profile = $client->getProfile()) {
                        $headers = array_merge($headers, array());
                        $row = array_merge($row, array(
                            round($profile->getCollector('time')->getDuration() / 1000).' ms',
                            round($profile->getCollector('memory')->getMemory() / 1000000, 1).' MB',
                            $profile->getCollector('db')->getQueryCount(),
                            $profile->getToken(),
                        ));
                    }
                    $rows[] = $row;
                }
            }

            $output->table($headers, $rows);
        }
    }

    private function writeCode(Client $client)
    {
        $response = $client->getResponse();

        switch (true) {
            case $response->isRedirection():
                $color = 'blue';
                break;
            case $response->isClientError():
                $color = 'yellow';
                break;
            case $response->isServerError():
                $color = 'red';
                break;
            default:
                $color = 'green';
                break;
        }

        return sprintf(
            '<bg=%s;fg=black;options=bold>%s</bg=%s;fg=black;options=bold> <fg=%s>%s</fg=%s>',
            $color,
            $response->getStatusCode(),
            $color,
            $color,
            Response::$statusTexts[$response->getStatusCode()],
            $color
        );
    }
}
