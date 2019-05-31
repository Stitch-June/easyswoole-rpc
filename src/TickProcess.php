<?php


namespace EasySwoole\Rpc;


use EasySwoole\Component\Openssl;
use EasySwoole\Component\Process\AbstractProcess;
use Swoole\Coroutine\Client;
use Swoole\Coroutine\Socket;

class TickProcess extends AbstractProcess
{
    public function run($arg)
    {
        /** @var Config $config */
        $config = $arg['config'];
        $serviceList = $arg['serviceList'];
        $this->addTick(3*1000,function ()use($config,$serviceList){
            /** @var AbstractService $service */
            foreach ($serviceList as $service){
                try{
                    $node = new ServiceNode();
                    $node->setServiceVersion($service->version());
                    $node->setServiceName($service->serviceName());
                    $node->setServerIp($config->getServerIp());
                    $node->setServerPort($config->getListenPort());
                    $node->setLastHeartBeat(time());
                    $node->setNodeId($config->getNodeId());
                    $config->getNodeManager()->serviceNodeHeartBeat($node);
                }catch (\Throwable $throwable){
                    $this->onException($throwable);
                }
                try{
                    $service->onTick($config);
                }catch (\Throwable $throwable){
                    $this->onException($throwable);
                }
            }
        });

        if($config->getBroadcastConfig()->isEnableBroadcast()){
            //对外广播
            $this->addTick($config->getBroadcastConfig()->getInterval()*1000,function ()use($config,$serviceList){
                $this->udpBroadcast($config,$serviceList,BroadcastCommand::COMMAND_OFF_LINE);
            });
        }
        if($config->getBroadcastConfig()->isEnableListen())
        {
            go(function ()use($config){
                $openssl = null;
                if(!empty($config->getBroadcastConfig()->getSecretKey())){
                    $openssl = new Openssl($openssl);
                }
                $socketServer = new Socket(AF_INET, SOCK_DGRAM);
                $socketServer->bind($config->getBroadcastConfig()->getListenAddress(), $config->getBroadcastConfig()->getListenPort());
                while (1){
                    $peer = null;
                    $data = $socketServer->recvfrom($peer);
                    if(empty($data)){
                        continue;
                    }
                    if($openssl){
                        $data = $openssl->decrypt($data);
                    }
                    $data = unserialize($data);
                    if($data instanceof BroadcastCommand){
                        $node = $data->getServiceNode();
                        if($data->getCommand() == $data::COMMAND_HEART_BEAT){
                            $config->getNodeManager()->serviceNodeHeartBeat($node);
                        }else if($data->getCommand() == $data::COMMAND_OFF_LINE){
                            $config->getNodeManager()->deleteServiceNode($node);
                        }
                    }
                }
            });
        }
    }

    protected function onShutDown()
    {
        /** @var Config $config */
        $config = $this->getConfig()['config'];
        $serviceList = $this->getConfig()['serviceList'];
        $this->udpBroadcast($config,$serviceList,BroadcastCommand::COMMAND_OFF_LINE);
    }

    protected function udpBroadcast(Config $config,array $serverList,int $command)
    {
        $client = new Client(SWOOLE_UDP);
        //遍历节点，并遍历广播地址发送
//        $client->sendto($address[0], $address[1], $data);
    }

    protected function onException(\Throwable $throwable, ...$args)
    {
        /** @var Config $config */
        $config = $this->getConfig()['config'];
        if($config->getTrigger()){
            $config->getTrigger()->throwable($throwable);
        }else{
            throw $throwable;
        }
    }
}